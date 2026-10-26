<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for asynchronous AI grading in the deferred_for_aitext behaviour.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbehaviour_deferred_for_aitext;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use qbehaviour_deferred_for_aitext\external\get_grading_status;
use qbehaviour_deferred_for_aitext\task\grade_response;
use question_attempt;
use question_engine;
use question_state;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

// This test file intentionally declares a test-double class alongside the testcase.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

/**
 * Grading task that returns a fixed result instead of calling the LLM.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_grade_response extends grade_response {
    /** @var array|null result returned by grade(). */
    public static $result = null;

    /** @var int number of times grade() was called. */
    public static $calls = 0;

    /** @var callable|null run inside grade(), standing in for work done elsewhere during the LLM call. */
    public static $duringgrade = null;

    #[\Override]
    protected function grade(question_attempt $qa): ?array {
        self::$calls++;
        if (self::$duringgrade) {
            $callback = self::$duringgrade;
            self::$duringgrade = null;
            $callback();
        }
        return self::$result;
    }
}

/**
 * Tests for asynchronous AI grading.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @category   test
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_deferred_for_aitext
 * @covers     \qbehaviour_deferred_for_aitext\task\grade_response
 * @covers     \qbehaviour_deferred_for_aitext\external\get_grading_status
 */
final class async_grading_test extends \advanced_testcase {
    /** @var \stdClass the quiz. */
    private $quiz;

    /** @var \stdClass the student. */
    private $student;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('async', 1, 'qbehaviour_deferred_for_aitext');
        testable_grade_response::$result = [
            '-aigrade' => 1,
            '-aifraction' => 0.5,
            '-aicomment' => 'Half right.',
            '-aiprompt' => 'Grade this.',
        ];
        testable_grade_response::$calls = 0;
        testable_grade_response::$duringgrade = null;

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'questionsperpage' => 0,
            'grade' => 10.0,
            'sumgrades' => 1.0,
            'preferredbehaviour' => 'deferredfeedback',
        ]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($this->quiz->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('aitext', 'editor', ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $this->quiz, 0, 1.0);
    }

    /**
     * Start an attempt, answer the aitext questions and submit the attempt.
     *
     * @param int $numslots number of slots in the quiz to answer.
     * @return \stdClass the attempt record.
     */
    private function submit_attempt(int $numslots = 1): \stdClass {
        $quizobj = quiz_settings::create($this->quiz->id, $this->student->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $this->student->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = quiz_attempt::create($attempt->id);
        $responses = [];
        for ($slot = 1; $slot <= $numslots; $slot++) {
            $responses[$slot] = ['answer' => 'The frog jumped over the log.', 'answerformat' => FORMAT_HTML];
        }
        $attemptobj->process_submitted_actions($timenow, false, $responses);
        $attemptobj = quiz_attempt::create($attempt->id);
        $attemptobj->process_submit($timenow, false);
        $attemptobj->process_grade_submission($timenow);
        return $attempt;
    }

    /**
     * Run the queued grading task(s) with the LLM call stubbed.
     *
     * @param int $attemptsavailable attempts left on the task.
     */
    private function run_queued_tasks(int $attemptsavailable = grade_response::MAX_ATTEMPTS): void {
        $tasks = \core\task\manager::get_adhoc_tasks(grade_response::class);
        $this->assertNotEmpty($tasks);
        foreach ($tasks as $queued) {
            $task = new testable_grade_response();
            $task->set_custom_data($queued->get_custom_data());
            $task->set_attempts_available($attemptsavailable);
            $task->execute();
        }
    }

    /**
     * Load the question attempt for the aitext slot.
     *
     * @param \stdClass $attempt
     * @return question_attempt
     */
    private function load_qa(\stdClass $attempt): question_attempt {
        return question_engine::load_questions_usage_by_activity($attempt->uniqueid)->get_question_attempt(1);
    }

    public function test_finish_queues_task_and_leaves_needs_grading(): void {
        $attempt = $this->submit_attempt();

        $qa = $this->load_qa($attempt);
        $this->assertSame('deferred_for_aitext', $qa->get_behaviour_name());
        $this->assertSame(question_state::$needsgrading, $qa->get_state());
        $this->assertTrue($qa->get_last_step()->has_behaviour_var('_aiqueued'));

        $tasks = \core\task\manager::get_adhoc_tasks(grade_response::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEquals($attempt->uniqueid, $task->get_custom_data()->qubaid);
        $this->assertEquals(1, $task->get_custom_data()->slot);
        $this->assertEquals($this->student->id, $task->get_userid());
    }

    public function test_sync_mode_does_not_queue(): void {
        set_config('async', 0, 'qbehaviour_deferred_for_aitext');
        $attempt = $this->submit_attempt();

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertFalse($this->load_qa($attempt)->get_last_step()->has_behaviour_var('_aiqueued'));
    }

    public function test_task_applies_grade_and_updates_quiz_grades(): void {
        global $DB;
        $attempt = $this->submit_attempt();
        $this->assertNull($DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]));

        $this->run_queued_tasks();

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertEqualsWithDelta(0.5, $qa->get_fraction(), 0.0001);
        $this->assertSame('Half right.', $qa->get_last_behaviour_var('_comment'));
        $this->assertSame('Grade this.', $qa->get_last_behaviour_var('_aiprompt'));
        $this->assertSame('Graded by AI', $qa->get_behaviour()->summarise_action($qa->get_last_step()));

        $this->assertEqualsWithDelta(0.5, $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]), 0.0001);
        $grade = $DB->get_field('quiz_grades', 'grade', ['quiz' => $this->quiz->id, 'userid' => $this->student->id]);
        $this->assertEqualsWithDelta(5.0, $grade, 0.0001);
    }

    public function test_regrade_reuses_ai_grade_without_llm_call(): void {
        global $DB;
        $attempt = $this->submit_attempt();
        $this->run_queued_tasks();
        $this->assertSame(1, testable_grade_response::$calls);
        // Clear the queue so we can check the regrade does not queue another task.
        $DB->delete_records('task_adhoc');

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->regrade_all_questions(true);
        question_engine::save_questions_usage_by_activity($quba);

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$gradedpartial, $qa->get_state());
        $this->assertEqualsWithDelta(0.5, $qa->get_fraction(), 0.0001);
        $this->assertSame('Half right.', $qa->get_last_behaviour_var('_comment'));
        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertSame(1, testable_grade_response::$calls);
    }

    public function test_task_does_nothing_when_teacher_graded_first(): void {
        $attempt = $this->submit_attempt();

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->manual_grade(1, 'Teacher says good.', 1.0, FORMAT_HTML);
        question_engine::save_questions_usage_by_activity($quba);

        $this->expectOutputRegex('/no longer waiting for AI grading/');
        $this->run_queued_tasks();

        $qa = $this->load_qa($attempt);
        $this->assertSame(question_state::$mangrright, $qa->get_state());
        $this->assertSame(0, testable_grade_response::$calls);
    }

    public function test_task_retries_then_leaves_for_manual_grading(): void {
        $attempt = $this->submit_attempt();
        testable_grade_response::$result = null;

        try {
            $this->run_queued_tasks(3);
            $this->fail('Expected the task to throw so cron retries it.');
        } catch (\moodle_exception $e) {
            $this->assertSame('err_aigradingfailed', $e->errorcode);
        }

        // Final attempt: no exception, response stays for the teacher.
        $this->expectOutputRegex('/left for manual grading/');
        $this->run_queued_tasks(1);
        $this->assertSame(question_state::$needsgrading, $this->load_qa($attempt)->get_state());
    }

    public function test_concurrency_limit_from_setting(): void {
        $task = new grade_response();
        $this->assertSame(grade_response::DEFAULT_CONCURRENCY, $task->get_concurrency_limit());

        set_config('asyncconcurrency', 4, 'qbehaviour_deferred_for_aitext');
        $this->assertSame(4, $task->get_concurrency_limit());
    }

    public function test_concurrent_tasks_leave_correct_quiz_total(): void {
        $this->add_second_question();
        $attempt = $this->submit_attempt(2);
        $tasks = array_values(\core\task\manager::get_adhoc_tasks(grade_response::class));
        $this->assertCount(2, $tasks);

        // While the first task waits for the LLM, the task for the other slot finishes.
        $other = new testable_grade_response();
        $other->set_custom_data($tasks[1]->get_custom_data());
        testable_grade_response::$duringgrade = fn() => $other->execute();

        $first = new testable_grade_response();
        $first->set_custom_data($tasks[0]->get_custom_data());
        $first->execute();

        $sumgrades = $this->get_sumgrades($attempt);
        $this->assertNotNull($sumgrades);
        $this->assertEqualsWithDelta(1.0, (float) $sumgrades, 0.0001);
    }

    public function test_grading_status_reports_pending_then_done(): void {
        $attempt = $this->submit_attempt();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->quiz->course, 'student');
        $this->setUser($this->student);

        $status = get_grading_status::execute($attempt->uniqueid);
        $this->assertSame(['total' => 1, 'pending' => 1], $status);

        $this->run_queued_tasks();
        $status = get_grading_status::execute($attempt->uniqueid);
        $this->assertSame(['total' => 1, 'pending' => 0], $status);
    }

    public function test_grading_status_denied_for_other_student(): void {
        $attempt = $this->submit_attempt();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->quiz->course, 'student');
        $this->setUser($other);

        $this->expectException(\required_capability_exception::class);
        get_grading_status::execute($attempt->uniqueid);
    }

    /**
     * Add a second aitext question, worth one mark, to the quiz.
     */
    private function add_second_question(): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($this->quiz->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('aitext', 'editor', ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $this->quiz, 0, 1.0);
    }

    /**
     * Read the stored attempt total.
     *
     * @param \stdClass $attempt
     * @return string|null
     */
    private function get_sumgrades(\stdClass $attempt): ?string {
        global $DB;
        return $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]);
    }
}
