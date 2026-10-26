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
 * Admin settings for the deferred_for_aitext question behaviour.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'qbehaviour_deferred_for_aitext/async',
        new lang_string('async', 'qbehaviour_deferred_for_aitext'),
        new lang_string('async_desc', 'qbehaviour_deferred_for_aitext'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'qbehaviour_deferred_for_aitext/asyncconcurrency',
        new lang_string('asyncconcurrency', 'qbehaviour_deferred_for_aitext'),
        new lang_string('asyncconcurrency_desc', 'qbehaviour_deferred_for_aitext'),
        \qbehaviour_deferred_for_aitext\task\grade_response::DEFAULT_CONCURRENCY,
        PARAM_INT,
        4
    ));
}
