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

namespace qbehaviour_deferred_for_aitext\task;

use question_attempt;
use question_engine;
use question_state;
use question_usage_by_activity;

/**
 * Adhoc task that grades one qtype_aitext response with the LLM.
 *
 * Queued by qbehaviour_deferred_for_aitext::process_finish() when asynchronous
 * grading is enabled. Running the LLM calls from cron, with a concurrency cap,
 * flattens the load peak at the end of an exam and removes LLM latency from
 * the student's finish request.
 *
 * The result is written back as a '-aigrade' behaviour action so it is stored
 * in the question engine like any other step and survives regrades without
 * another LLM call.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_response extends \core\task\adhoc_task {
    /** @var int Default number of tasks of this class allowed to run at once. */
    const DEFAULT_CONCURRENCY = 2;

    /** @var int Number of attempts before the response is left for manual grading. */
    const MAX_ATTEMPTS = 5;

    /**
     * Queue grading for a question attempt.
     *
     * The task runs as the student so AI backends apply their usual per-user
     * permission and quota checks.
     *
     * @param question_attempt $qa the attempt that has just been finished.
     */
    public static function queue(question_attempt $qa): void {
        $task = new self();
        $task->set_custom_data([
            'qubaid' => (int) $qa->get_usage_id(),
            'slot' => (int) $qa->get_slot(),
        ]);
        $task->set_userid($qa->get_step(0)->get_user_id());
        $task->set_attempts_available(self::MAX_ATTEMPTS);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    #[\Override]
    public function get_name(): string {
        return get_string('task_graderesponse', 'qbehaviour_deferred_for_aitext');
    }

    #[\Override]
    protected function get_default_concurrency_limit(): int {
        $limit = get_config('qbehaviour_deferred_for_aitext', 'asyncconcurrency');
        if ($limit === false || $limit === '') {
            return self::DEFAULT_CONCURRENCY;
        }
        return max(0, (int) $limit);
    }

    #[\Override]
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $data = $this->get_custom_data();
        if (!$DB->record_exists('question_usages', ['id' => $data->qubaid])) {
            mtrace("Question usage {$data->qubaid} no longer exists, nothing to grade.");
            return;
        }

        $quba = question_engine::load_questions_usage_by_activity($data->qubaid);
        $qa = $quba->get_question_attempt($data->slot);
        if (!$this->is_waiting_for_ai($qa)) {
            mtrace("Usage {$data->qubaid} slot {$data->slot} is no longer waiting for AI grading.");
            return;
        }

        $result = $this->grade($qa);
        if ($result === null) {
            if ($this->get_attempts_available() > 1) {
                // Throwing makes cron retry with exponential backoff.
                throw new \moodle_exception('err_aigradingfailed', 'qbehaviour_deferred_for_aitext');
            }
            mtrace("AI grading failed for usage {$data->qubaid} slot {$data->slot}, left for manual grading.");
            return;
        }

        $this->apply_grade($data->qubaid, $data->slot, $result);
    }

    /**
     * Whether the attempt is finished and still waiting for this task.
     *
     * @param question_attempt $qa
     * @return bool
     */
    protected function is_waiting_for_ai(question_attempt $qa): bool {
        return $qa->get_behaviour_name() === 'deferred_for_aitext'
            && $qa->get_state() === question_state::$needsgrading
            && $qa->get_last_step()->has_behaviour_var('_aiqueued');
    }

    /**
     * Ask the question type to grade the last response.
     *
     * @param question_attempt $qa
     * @return array|null behaviour vars for the -aigrade action, or null if no grade was produced.
     */
    protected function grade(question_attempt $qa): ?array {
        $question = $qa->get_question();
        [$fraction, $state] = $question->grade_response($qa->get_last_qt_data());
        if ($state === question_state::$needsgrading) {
            return null;
        }

        $vars = ['-aigrade' => 1, '-aifraction' => (float) $fraction];
        if ($question->lastaicomment !== null) {
            $vars['-aicomment'] = $question->lastaicomment;
        }
        if ($question->lastaiprompt !== null) {
            $vars['-aiprompt'] = $question->lastaiprompt;
        }
        if ($question->lastspellcheckresponse !== null) {
            $vars['-aispellcheck'] = $question->lastspellcheckresponse;
        }
        return $vars;
    }

    /**
     * Store the AI grade in the usage and update the owning activity's grades.
     *
     * The action is processed directly rather than through simulated POST data,
     * so the -ai* variables are never accepted from a browser request.
     *
     * Tasks for other slots of the same usage may run at the same time, so the
     * usage is reloaded under a lock. Otherwise the quiz total is calculated
     * from a copy loaded before the LLM call, where other slots may still
     * need grading.
     *
     * @param int $qubaid
     * @param int $slot
     * @param array $vars behaviour vars for the -aigrade action.
     */
    protected function apply_grade(int $qubaid, int $slot, array $vars): void {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('qbehaviour_deferred_for_aitext');
        $lock = $lockfactory->get_lock('quba' . $qubaid, 60);
        if (!$lock) {
            // Throwing makes cron retry with exponential backoff.
            throw new \moodle_exception('locktimeout');
        }

        try {
            $quba = question_engine::load_questions_usage_by_activity($qubaid);
            if (!$this->is_waiting_for_ai($quba->get_question_attempt($slot))) {
                mtrace("Usage {$qubaid} slot {$slot} was graded while waiting for the AI.");
                return;
            }

            $transaction = $DB->start_delegated_transaction();
            $quba->process_action($slot, $vars);
            question_engine::save_questions_usage_by_activity($quba);

            if ($quba->get_owning_component() === 'mod_quiz') {
                $this->update_quiz_grades($quba);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Recalculate the quiz attempt total and the user's final quiz grade.
     *
     * @param question_usage_by_activity $quba
     */
    protected function update_quiz_grades(question_usage_by_activity $quba): void {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $quba->get_id()]);
        if (!$attempt) {
            return;
        }
        if ($attempt->state === \mod_quiz\quiz_attempt::FINISHED) {
            $attempt->sumgrades = $quba->get_total_mark();
            $attempt->timemodified = time();
            $DB->update_record('quiz_attempts', $attempt);
        }
        if (!$attempt->preview) {
            \mod_quiz\quiz_settings::create($attempt->quiz)
                ->get_grade_calculator()
                ->recompute_final_grade($attempt->userid);
        }
    }
}
