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

/**
 * Renderer for the deferred question behaviour for qtype_aitext.
 *
 * @subpackage deferred_for_aitext
 * @copyright  2026 ISB, Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_deferred_for_aitext_renderer extends qbehaviour_renderer {

    public function manual_comment_view(question_attempt $qa, question_display_options $options) {
        $output = '';

        if ($qa->has_manual_comment()) {
            // Teacher comment takes priority.
            $output .= get_string('commentx', 'question',
                $qa->get_behaviour(false)->format_comment(null, null, $options->context));
        } else {
            // Fall back to AI-generated comment (search all steps, not just the last).
            $aicomment = $qa->get_last_behaviour_var('_comment');
            if ($aicomment !== null) {
                $output .= get_string('commentx', 'question',
                    format_text($aicomment, FORMAT_HTML, ['context' => $options->context]));
            }
        }

        if ($options->manualcommentlink) {
            $url = new moodle_url($options->manualcommentlink, ['slot' => $qa->get_slot()]);
            $link = $this->output->action_link($url, get_string('commentormark', 'question'),
                new popup_action('click', $url, 'commentquestion',
                    ['width' => 600, 'height' => 800]));
            $output .= html_writer::tag('div', $link, ['class' => 'commentlink']);
        }
        return $output;
    }
}
