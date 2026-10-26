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
 * Language strings for the deferred_for_aitext question behaviour.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @copyright  2026 ISB Bayern
 * @author     Paola Maneggia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['aigradeaction'] = 'Graded by AI';
$string['async'] = 'Asynchronous AI grading';
$string['async_desc'] = 'Grade AI text responses in a background task instead of during the request that finishes the attempt. This spreads the load on the AI backend over time instead of sending all requests when many students finish at once. Requires cron to run frequently. Responses stay in the "Requires grading" state until the task has run.';
$string['asyncconcurrency'] = 'Maximum concurrent AI grading tasks';
$string['asyncconcurrency_desc'] = 'The maximum number of AI grading tasks that may run at the same time, which is the maximum number of grading requests sent to the AI backend at once. Keep this below the "Ad hoc task runner limit" (task_adhoc_concurrency_limit) so other background tasks are not blocked. 0 means no limit.';
$string['err_aigradingfailed'] = 'The AI backend did not return a grade. The task will be retried.';
$string['pluginname'] = 'Deferred feedback for AI grading';
$string['privacy:metadata'] = 'The Deferred feedback for AI grading behaviour plugin does not store any personal data. AI-generated grading data is stored as question attempt step data, which is managed by the core question engine privacy provider.';
$string['spellcheckeditaction'] = 'Teacher edited spellcheck';
$string['task_graderesponse'] = 'Grade AI text response';
