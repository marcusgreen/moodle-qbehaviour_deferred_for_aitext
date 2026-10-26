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
 * Web service definitions for the deferred_for_aitext behaviour.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'qbehaviour_deferred_for_aitext_get_grading_status' => [
        'classname' => \qbehaviour_deferred_for_aitext\external\get_grading_status::class,
        'description' => 'Report how many responses are still waiting for asynchronous AI grading.',
        'type' => 'read',
        'ajax' => true,
    ],
];
