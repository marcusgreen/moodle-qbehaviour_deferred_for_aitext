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
 * Question behaviour type for deferred feedback with AI text grading.
 *
 * @package    qbehaviour_deferredaitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../deferredfeedback/behaviourtype.php');

/**
 * Question behaviour type information for deferred feedback with AI text grading.
 *
 * This is not an archetypal behaviour — it is selected automatically by
 * qtype_aitext_question::make_behaviour() and should not appear in the
 * quiz settings UI.
 *
 * @package    qbehaviour_deferredaitext
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_deferred_for_aitext_type extends qbehaviour_deferredfeedback_type {

    /**
     * This behaviour is not archetypal — it should not appear in quiz settings.
     *
     * @return bool false always.
     */
    public function is_archetypal() {
        return false;
    }
}
