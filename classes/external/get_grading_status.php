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

namespace qbehaviour_deferred_for_aitext\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use question_engine;
use question_state;

/**
 * Report how many responses in a usage are still waiting for asynchronous AI grading.
 *
 * Polled by the review page so it can show progress and reload once grading has finished.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_grading_status extends external_api {
    /**
     * Declare the method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'qubaid' => new external_value(PARAM_INT, 'The question usage id'),
        ]);
    }

    /**
     * Count the queued and still waiting responses in a usage.
     *
     * @param int $qubaid the question usage id.
     * @return array with keys total and pending.
     */
    public static function execute(int $qubaid): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        ['qubaid' => $qubaid] = self::validate_parameters(self::execute_parameters(), ['qubaid' => $qubaid]);

        $usage = $DB->get_record('question_usages', ['id' => $qubaid], '*', MUST_EXIST);
        $context = \context::instance_by_id($usage->contextid);
        self::validate_context($context);

        if ($usage->component === 'mod_quiz') {
            $attempt = $DB->get_record('quiz_attempts', ['uniqueid' => $qubaid], 'id, userid', MUST_EXIST);
            if ($attempt->userid != $USER->id) {
                require_capability('mod/quiz:viewreports', $context);
            }
        } else if (!($context instanceof \context_user) || $context->instanceid != $USER->id) {
            // Question previews are owned by the user context of the person previewing.
            throw new \required_capability_exception($context, 'moodle/question:useall', 'nopermissions', '');
        }

        $quba = question_engine::load_questions_usage_by_activity($qubaid);
        $total = 0;
        $pending = 0;
        foreach ($quba->get_slots() as $slot) {
            $qa = $quba->get_question_attempt($slot);
            if ($qa->get_behaviour_name() !== 'deferred_for_aitext') {
                continue;
            }
            $queued = false;
            foreach ($qa->get_step_iterator() as $step) {
                if ($step->has_behaviour_var('_aiqueued')) {
                    $queued = true;
                    break;
                }
            }
            if (!$queued) {
                continue;
            }
            $total++;
            if (
                $qa->get_state() === question_state::$needsgrading
                && $qa->get_last_step()->has_behaviour_var('_aiqueued')
            ) {
                $pending++;
            }
        }

        return ['total' => $total, 'pending' => $pending];
    }

    /**
     * Describe the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Number of responses queued for AI grading'),
            'pending' => new external_value(PARAM_INT, 'Number of those still waiting to be graded'),
        ]);
    }
}
