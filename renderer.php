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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/behaviour/deferredfeedback/renderer.php');

/**
 * Renderer for the deferred feedback question behaviour adapted for qtype_aitext.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 ISB, Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_deferred_for_aitext_renderer extends qbehaviour_deferredfeedback_renderer {
    /**
     * Override manual_comment_fields to pre-fill the editor with AI-generated
     * feedback when no manual comment exists yet.
     *
     * This is a copy of qbehaviour_renderer::manual_comment_fields() with a
     * single change: when there is no existing manual comment, the AI-generated
     * feedback from the cached behaviour variable '_comment' is used as the
     * initial editor content instead of an empty string.
     *
     * @param question_attempt $qa a question attempt.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function manual_comment_fields(question_attempt $qa, question_display_options $options) {
        global $CFG;

        require_once($CFG->dirroot . '/lib/filelib.php');
        require_once($CFG->dirroot . '/repository/lib.php');

        $inputname = $qa->get_behaviour_field_name('comment');
        $id = $inputname . '_id';
        [$commenttext, $commentformat, $commentstep] = $qa->get_current_manual_comment();

        $editor = editors_get_preferred_editor($commentformat);
        $strformats = format_text_menu();
        $formats = $editor->get_supported_formats();
        foreach ($formats as $fid) {
            $formats[$fid] = $strformats[$fid];
        }

        $draftitemareainputname = $qa->get_behaviour_field_name('comment:itemid');
        $draftitemid = optional_param($draftitemareainputname, false, PARAM_INT);

        if (!$draftitemid && $commentstep === null) {
            $aicomment = $qa->get_last_behaviour_var('_comment');
            $commenttext = ($aicomment !== null) ? $aicomment : '';
            $commentformat = FORMAT_HTML;
            $draftitemid = file_get_unused_draft_itemid();
        } else if (!$draftitemid) {
            [$draftitemid, $commenttext] = $commentstep->prepare_response_files_draft_itemid_with_text(
                'bf_comment',
                $options->context->id,
                $commenttext
            );
        }

        $editor->set_text($commenttext);
        $editor->use_editor(
            $id,
            question_utils::get_editor_options($options->context),
            question_utils::get_filepicker_options($options->context, $draftitemid)
        );

        $commenteditor = html_writer::tag('div', html_writer::tag(
            'textarea',
            s($commenttext),
            ['id' => $id, 'name' => $inputname, 'rows' => 3, 'cols' => 60]
        ));

        $attributes = ['type'  => 'hidden', 'name'  => $draftitemareainputname, 'value' => $draftitemid];
        $commenteditor .= html_writer::empty_tag('input', $attributes);

        $editorformat = '';
        if (count($formats) == 1) {
            reset($formats);
            $editorformat .= html_writer::empty_tag('input', ['type' => 'hidden',
                'name' => $inputname . 'format', 'value' => key($formats)]);
        } else {
            $editorformat = html_writer::start_tag('div', ['class' => 'fitem']);
            $editorformat .= html_writer::start_tag('div', ['class' => 'fitemtitle']);
            $editorformat .= html_writer::tag('label', get_string('format'), ['for' => 'menu' . $inputname . 'format']);
            $editorformat .= html_writer::end_tag('div');
            $editorformat .= html_writer::start_tag('div', ['class' => 'felement fhtmleditor']);
            $editorformat .= html_writer::select($formats, $inputname . 'format', $commentformat, '');
            $editorformat .= html_writer::end_tag('div');
            $editorformat .= html_writer::end_tag('div');
        }

        $comment = html_writer::tag(
            'div',
            html_writer::tag(
                'div',
                html_writer::tag(
                    'label',
                    get_string('comment', 'question'),
                    ['for' => $id]
                ),
                ['class' => 'fitemtitle']
            ) .
            html_writer::tag('div', $commenteditor, ['class' => 'felement fhtmleditor', 'data-fieldtype' => "editor"]),
            ['class' => 'fitem']
        );
        $comment .= $editorformat;

        $mark = '';
        if ($qa->get_max_mark()) {
            $currentmark = $qa->get_current_manual_mark();
            $maxmark = $qa->get_max_mark();

            $fieldsize = strlen($qa->format_max_mark($options->markdp)) - 1;
            $markfield = $qa->get_behaviour_field_name('mark');

            $attributes = [
                'type' => 'text',
                'size' => $fieldsize,
                'name' => $markfield,
                'id' => $markfield,
            ];
            if (!is_null($currentmark)) {
                $attributes['value'] = $currentmark;
            }

            $markrange = html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => $qa->get_behaviour_field_name('maxmark'),
                    'value' => $maxmark,
                ]) . html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => $qa->get_control_field_name('minfraction'),
                    'value' => $qa->get_min_fraction(),
                ]) . html_writer::empty_tag('input', [
                    'type' => 'hidden',
                    'name' => $qa->get_control_field_name('maxfraction'),
                    'value' => $qa->get_max_fraction(),
                ]);

            $error = $qa->validate_manual_mark($currentmark);
            $errorclass = '';
            if ($error !== '') {
                $errorclass = ' error alert-danger';
                $error = html_writer::tag(
                    'span',
                    $error,
                    ['class' => 'error']
                ) . html_writer::empty_tag('br');
            }

            $a = new stdClass();
            $a->max = $qa->format_max_mark($options->markdp);
            $a->mark = html_writer::empty_tag('input', $attributes);
            $mark = html_writer::tag('div', html_writer::tag(
                'div',
                html_writer::tag(
                    'label',
                    get_string('mark', 'question'),
                    ['for' => $markfield]
                ),
                ['class' => 'fitemtitle']
            ) .
                html_writer::tag('div', $error . get_string('xoutofmax', 'question', $a) .
                    $markrange, ['class' => 'felement ftext' . $errorclass]), ['class' => 'fitem']);
        }

        return html_writer::tag('fieldset', html_writer::tag(
            'div',
            $comment . $mark,
            ['class' => 'fcontainer clearfix']
        ), ['class' => 'hidden']);
    }

    /**
     * Override manual_comment_view to display as manual comment the ai generated feedback, as long
     * as there is no manual grading. Based on manual_comment_view in qbehaviour_renderer.
     * @param question_attempt $qa
     * @param question_display_options $options
     * @return string
     * @throws \core\exception\coding_exception
     * @throws \core\exception\moodle_exception
     * @throws coding_exception
     */
    public function manual_comment_view(question_attempt $qa, question_display_options $options) {
        $output = '';

        [$commenttext, $commentformat, $commentstep] = $qa->get_manual_comment();
        if ($commenttext !== null && trim($commenttext) !== '') {
            // Non empty teacher comment takes priority.
            $output .= get_string(
                'commentx',
                'question',
                $qa->get_behaviour(false)->format_comment(null, null, $options->context)
            );
        } else {
            // Fall back to AI-generated comment (search all steps, not just the last).
            $aicomment = $qa->get_last_behaviour_var('_comment');
            if ($aicomment !== null) {
                $output .= get_string(
                    'commentx',
                    'question',
                    format_text($aicomment, FORMAT_HTML, ['context' => $options->context])
                );
            }
        }

        if ($options->manualcommentlink) {
            $url = new moodle_url($options->manualcommentlink, ['slot' => $qa->get_slot()]);
            $link = $this->output->action_link(
                $url,
                get_string('commentormark', 'question'),
                new popup_action(
                    'click',
                    $url,
                    'commentquestion',
                    ['width' => 600, 'height' => 800]
                )
            );
            $output .= html_writer::tag('div', $link, ['class' => 'commentlink']);
        }
        return $output;
    }
}
