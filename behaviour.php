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
 * Question behaviour for qtype_aitext questions with deferred feedback.
 *
 * This behaviour extends the standard deferredfeedback behaviour to properly
 * persist AI-generated grading data into the grading step via the question engine
 * API, avoiding raw database writes from within the question type.
 *
 * After grade_response() runs and returns a [fraction, state] pair, this behaviour
 * reads the AI results cached on the question object and writes them as cached
 * behaviour variables (_-prefixed) into the pending step before it is committed.
 * Variable naming convention used:
 * - Cached behaviour vars (_-prefix, written by apply_ai_results_to_step):
 *   _comment          The AI-generated feedback text (HTML).
 *   _commentformat    The format of the feedback (FORMAT_HTML).
 *   _aiprompt         The full rendered prompt sent to the AI.
 *   _spellcheckresponse  The AI spellcheck/grammar correction (if enabled).
 *
 * - Manual grading vars (written later by process_comment() via manual_grade()):
 *   comment           Teacher's manual comment (overrides AI comment for display).
 *   commentformat     Format of teacher's comment.
 *   mark              Teacher's mark (absolute).
 *   maxmark           Maximum mark at time of manual grading.
 *
 * - Asynchronous grading vars (when qbehaviour_deferred_for_aitext/async is on):
 *   _aiqueued         Set on the finish step when grading was queued to a task.
 *   aigrade           Marks the step written by the grade_response task.
 *   aifraction, aicomment, aiprompt, aispellcheck
 *                     The AI result, stored as submitted vars so regrades replay
 *                     it without another LLM call.
 *
 * The renderer should prefer teacher vars (comment) over AI vars (_comment) when
 * both are present on a step.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/deferredfeedback/behaviour.php');

/**
 * Question behaviour for qtype_aitext questions with deferred feedback.
 *
 * This behaviour extends the standard deferredfeedback behaviour to properly
 * persist AI-generated grading data into the grading step via the question engine
 * API, avoiding raw database writes from within the question type.
 *
 * After grade_response() runs and returns a [fraction, state] pair, this behaviour
 * reads the AI results cached on the question object and writes them as cached
 * behaviour variables (_-prefixed) into the pending step before it is committed.
 * Variable naming convention used:
 * - Cached behaviour vars (_-prefix, written by apply_ai_results_to_step):
 *   _comment          The AI-generated feedback text (HTML).
 *   _commentformat    The format of the feedback (FORMAT_HTML).
 *   _aiprompt         The full rendered prompt sent to the AI.
 *   _spellcheckresponse  The AI spellcheck/grammar correction (if enabled).
 *
 * - Manual grading vars (written later by process_comment() via manual_grade()):
 *   comment           Teacher's manual comment (overrides AI comment for display).
 *   commentformat     Format of teacher's comment.
 *   mark              Teacher's mark (absolute).
 *   maxmark           Maximum mark at time of manual grading.
 *
 * - Asynchronous grading vars (when qbehaviour_deferred_for_aitext/async is on):
 *   _aiqueued         Set on the finish step when grading was queued to a task.
 *   aigrade           Marks the step written by the grade_response task.
 *   aifraction, aicomment, aiprompt, aispellcheck
 *                     The AI result, stored as submitted vars so regrades replay
 *                     it without another LLM call.
 *
 * The renderer should prefer teacher vars (comment) over AI vars (_comment) when
 * both are present on a step.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_deferred_for_aitext extends qbehaviour_deferredfeedback {
    /**
     * Only compatible with qtype_aitext_question instances.
     *
     * @param question_definition $question the question.
     * @return bool true if this behaviour can be used with this question type.
     */
    public function is_compatible_question(question_definition $question): bool {
        return $question instanceof qtype_aitext_question;
    }

    /**
     * Process the finish action.
     *
     * In synchronous mode (default), calls the parent process_finish() which invokes
     * grade_response() on the question and sets the fraction and state on the pending
     * step. After this, the AI results that grade_response() cached on the question
     * object are written as cached behaviour variables to the pending step via
     * apply_ai_results_to_step().
     *
     * In asynchronous mode the LLM is not called here. The step is left in the
     * needsgrading state and an adhoc task is queued which grades the response
     * later and submits the result through process_aigrade().
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_finish(question_attempt_pending_step $pendingstep) {
        if (self::is_async_enabled()) {
            return $this->process_finish_async($pendingstep);
        }

        // Let the parent call grade_response() and set fraction + state on the step.
        $result = parent::process_finish($pendingstep);

        if ($result === question_attempt::KEEP) {
            // The grade_response() call has run. Transfer AI-cached results from
            // the question object to the pending step as cached behaviour variables.
            $this->apply_ai_results_to_step($pendingstep);
        }

        return $result;
    }

    /**
     * Whether asynchronous AI grading is enabled for this site.
     *
     * @return bool
     */
    public static function is_async_enabled(): bool {
        return (bool) get_config('qbehaviour_deferred_for_aitext', 'async');
    }

    /**
     * Finish the attempt without grading and queue the AI grading task.
     *
     * Mirrors qbehaviour_deferredfeedback::process_finish() except that
     * grade_response() is not called. When the step is replayed during a regrade
     * (it already has an id) no new task is queued: the AI grade step that
     * follows it is replayed as well.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    protected function process_finish_async(question_attempt_pending_step $pendingstep): bool {
        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
        } else {
            $pendingstep->set_state(question_state::$needsgrading);
            $pendingstep->set_behaviour_var('_aiqueued', 1);
            if (empty($pendingstep->get_id()) && $this->qa->get_usage_id()) {
                \qbehaviour_deferred_for_aitext\task\grade_response::queue($this->qa);
            }
        }
        $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        return question_attempt::KEEP;
    }

    /**
     * Apply an AI grade computed asynchronously by the grade_response task.
     *
     * The AI results arrive as submitted behaviour variables (-aifraction,
     * -aicomment, -aiprompt, -aispellcheck) so that a regrade replays them
     * without calling the LLM again. They are copied into the same cached
     * variables the synchronous path writes, so the renderer needs no changes.
     *
     * @param question_attempt_pending_step $pendingstep the step being processed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    protected function process_aigrade(question_attempt_pending_step $pendingstep): bool {
        if ($this->qa->get_state() !== question_state::$needsgrading) {
            // Already graded, e.g. a teacher got there first.
            return question_attempt::DISCARD;
        }

        $fraction = min(max(0.0, (float) $pendingstep->get_behaviour_var('aifraction')), 1.0);
        $pendingstep->set_fraction($fraction);
        $pendingstep->set_state(question_state::graded_state_for_fraction($fraction));

        $comment = $pendingstep->get_behaviour_var('aicomment');
        if ($comment !== null) {
            $pendingstep->set_behaviour_var('_comment', $comment);
            $pendingstep->set_behaviour_var('_commentformat', (string) FORMAT_HTML);
        }
        $prompt = $pendingstep->get_behaviour_var('aiprompt');
        if ($prompt !== null) {
            $pendingstep->set_behaviour_var('_aiprompt', $prompt);
        }
        $spellcheck = $pendingstep->get_behaviour_var('aispellcheck');
        if ($spellcheck !== null) {
            $pendingstep->set_behaviour_var('_spellcheckresponse', $spellcheck);
        }
        return question_attempt::KEEP;
    }

    /**
     * Write AI-computed grading results from the question properties onto a pending step.
     *
     * After grade_response() runs, the question saves its AI results on public
     * properties ($lastaicomment, $lastaiprompt, $lastspellcheckresponse). This
     * method writes them as cached behaviour variables (key '-_name' in step data)
     * so they are persisted by the question engine unit-of-work.
     *
     * @param question_attempt_pending_step $pendingstep The step currently being built.
     */
    protected function apply_ai_results_to_step(question_attempt_pending_step $pendingstep): void {
        $question = $this->question;

        if (isset($question->lastaicomment) && $question->lastaicomment !== null) {
            $pendingstep->set_behaviour_var('_comment', $question->lastaicomment);
            $pendingstep->set_behaviour_var('_commentformat', (string) FORMAT_HTML);
        }

        if (isset($question->lastaiprompt) && $question->lastaiprompt !== null) {
            $pendingstep->set_behaviour_var('_aiprompt', $question->lastaiprompt);
        }

        if (isset($question->lastspellcheckresponse) && $question->lastspellcheckresponse !== null) {
            $pendingstep->set_behaviour_var('_spellcheckresponse', $question->lastspellcheckresponse);
        }
    }

    /**
     * Dispatch the processing of a pending step to the appropriate handler.
     *
     * If the pending step contains an 'aigrade' behaviour variable, it carries
     * the result of the asynchronous grading task and is handled by
     * process_aigrade().
     *
     * If the pending step contains a 'spellcheckedit' behaviour variable, this
     * means a teacher has submitted an edited spellcheck correction via the
     * dynamic form. In that case, processing is delegated to
     * process_spellcheck_edit(). Otherwise, the parent deferredfeedback
     * process_action() handles the step normally (save, finish, comment).
     *
     * @param question_attempt_pending_step $pendingstep a partially initialised step
     *      containing all the information about the action being performed.
     * @return bool question_attempt::KEEP or question_attempt::DISCARD.
     */
    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('aigrade')) {
            return $this->process_aigrade($pendingstep);
        }
        if ($pendingstep->has_behaviour_var('spellcheckedit')) {
            return $this->process_spellcheck_edit($pendingstep);
        }
        return parent::process_action($pendingstep);
    }

    /**
     * The step has a 'spellcheckedit' behaviour variable, meaning the teacher has
     * submitted an edited version of the student's response after using the AI
     * spellcheck feature. We want to keep the step and update its state and fraction
     * to match the current state of the attempt, since they do not change but we want
     * to persist the edited response for display in the renderer.
     * @param question_attempt_pending_step $pendingstep
     * @return bool
     */
    protected function process_spellcheck_edit(question_attempt_pending_step $pendingstep): bool {
        if (!$this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }
        // Keep the current state — this doesn't change the grade.
        $pendingstep->set_state($this->qa->get_state());
        $pendingstep->set_fraction($this->qa->get_fraction());
        return question_attempt::KEEP;
    }

    /**
     * Summarise what happened in a given step.
     *
     * @param question_attempt_step $step the step to summarise.
     * @return string a plain-text summary.
     */
    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('aigrade')) {
            return get_string('aigradeaction', 'qbehaviour_deferred_for_aitext');
        }
        if ($step->has_behaviour_var('spellcheckedit')) {
            return get_string('spellcheckeditaction', 'qbehaviour_deferred_for_aitext');
        }
        return parent::summarise_action($step);
    }
}
