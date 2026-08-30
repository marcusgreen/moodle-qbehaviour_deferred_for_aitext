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
 * Unit tests for the deferred_for_aitext question behaviour.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @category   test
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qbehaviour_deferred_for_aitext;

use question_attempt;
use question_attempt_pending_step;
use question_state;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../../engine/lib.php');
require_once(__DIR__ . '/../../../engine/tests/helpers.php');
require_once(__DIR__ . '/../behaviour.php');

// This test file intentionally declares a test-double class alongside the testcase.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

/**
 * Test double that bypasses the question_behaviour constructor and exposes the
 * protected methods and properties under test.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_deferred_for_aitext extends \qbehaviour_deferred_for_aitext {
    /**
     * Bypass the parent constructor; the tests inject dependencies directly.
     */
    public function __construct() {
        // Deliberately skip the parent constructor: these unit tests inject
        // the $qa and $question dependencies directly.
    }

    /**
     * Inject the question attempt dependency.
     * @param question_attempt $qa
     */
    public function set_qa($qa): void {
        $this->qa = $qa;
    }

    /**
     * Inject the question dependency.
     * @param object $question
     */
    public function set_question($question): void {
        $this->question = $question;
    }

    /**
     * Expose apply_ai_results_to_step().
     * @param question_attempt_pending_step $pendingstep
     */
    public function public_apply_ai_results_to_step(question_attempt_pending_step $pendingstep): void {
        $this->apply_ai_results_to_step($pendingstep);
    }

    /**
     * Expose process_spellcheck_edit().
     * @param question_attempt_pending_step $pendingstep
     * @return bool
     */
    public function public_process_spellcheck_edit(question_attempt_pending_step $pendingstep): bool {
        return $this->process_spellcheck_edit($pendingstep);
    }
}

/**
 * Unit tests for the deferred_for_aitext behaviour class.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @category   test
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_deferred_for_aitext
 */
final class behaviour_test extends \basic_testcase {
    /**
     * apply_ai_results_to_step() copies the AI results cached on the question
     * onto the pending step as cached behaviour variables.
     */
    public function test_apply_ai_results_to_step_writes_all_vars(): void {
        $question = new stdClass();
        $question->lastaicomment = 'Well argued.';
        $question->lastaiprompt = 'Grade this essay: ...';
        $question->lastspellcheckresponse = 'No spelling errors.';

        $behaviour = new testable_deferred_for_aitext();
        $behaviour->set_question($question);

        $step = new question_attempt_pending_step();
        $behaviour->public_apply_ai_results_to_step($step);

        $this->assertSame('Well argued.', $step->get_behaviour_var('_comment'));
        $this->assertSame((string) FORMAT_HTML, $step->get_behaviour_var('_commentformat'));
        $this->assertSame('Grade this essay: ...', $step->get_behaviour_var('_aiprompt'));
        $this->assertSame('No spelling errors.', $step->get_behaviour_var('_spellcheckresponse'));
    }

    /**
     * Null AI results are not written, so nothing pollutes the step data.
     */
    public function test_apply_ai_results_to_step_skips_nulls(): void {
        $question = new stdClass();
        $question->lastaicomment = null;
        $question->lastaiprompt = null;
        $question->lastspellcheckresponse = null;

        $behaviour = new testable_deferred_for_aitext();
        $behaviour->set_question($question);

        $step = new question_attempt_pending_step();
        $behaviour->public_apply_ai_results_to_step($step);

        $this->assertFalse($step->has_behaviour_var('_comment'));
        $this->assertFalse($step->has_behaviour_var('_commentformat'));
        $this->assertFalse($step->has_behaviour_var('_aiprompt'));
        $this->assertFalse($step->has_behaviour_var('_spellcheckresponse'));
    }

    /**
     * A spellcheck edit on an unfinished attempt is discarded so it cannot
     * corrupt the attempt state.
     */
    public function test_process_spellcheck_edit_discards_when_not_finished(): void {
        $qa = $this->createMock(question_attempt::class);
        $qa->method('get_state')->willReturn(question_state::$todo);

        $behaviour = new testable_deferred_for_aitext();
        $behaviour->set_qa($qa);

        $step = new question_attempt_pending_step();
        $result = $behaviour->public_process_spellcheck_edit($step);

        $this->assertSame(question_attempt::DISCARD, $result);
    }

    /**
     * A spellcheck edit on a finished attempt is kept, preserving the existing
     * state and fraction (the grade must not change).
     */
    public function test_process_spellcheck_edit_keeps_and_preserves_grade(): void {
        $qa = $this->createMock(question_attempt::class);
        $qa->method('get_state')->willReturn(question_state::$gradedright);
        $qa->method('get_fraction')->willReturn(1.0);

        $behaviour = new testable_deferred_for_aitext();
        $behaviour->set_qa($qa);

        $step = new question_attempt_pending_step();
        $result = $behaviour->public_process_spellcheck_edit($step);

        $this->assertSame(question_attempt::KEEP, $result);
        $this->assertSame(question_state::$gradedright, $step->get_state());
        $this->assertSame(1.0, $step->get_fraction());
    }

    /**
     * summarise_action() returns the dedicated string for a spellcheck-edit step.
     */
    public function test_summarise_action_spellcheck_edit(): void {
        $behaviour = new testable_deferred_for_aitext();

        $step = new question_attempt_pending_step(['-spellcheckedit' => 'corrected text']);
        $summary = $behaviour->summarise_action($step);

        $this->assertSame(
            get_string('spellcheckeditaction', 'qbehaviour_deferred_for_aitext'),
            $summary
        );
    }
}
