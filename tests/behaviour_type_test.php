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

namespace qbehaviour_deferred_for_aitext;

use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../../../engine/lib.php');
require_once(__DIR__ . '/../../../engine/tests/helpers.php');

/**
 * Unit tests for the deferred_for_aitext behaviour type class.
 *
 * @package    qbehaviour_deferred_for_aitext
 * @category   test
 * @copyright  2026 ISB Bayern
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \qbehaviour_deferred_for_aitext_type
 */
final class behaviour_type_test extends \basic_testcase {
    /** @var \qbehaviour_deferred_for_aitext_type */
    protected $behaviourtype;

    public function setUp(): void {
        parent::setUp();
        $this->behaviourtype = question_engine::get_behaviour_type('deferred_for_aitext');
    }

    public function test_is_not_archetypal(): void {
        // This behaviour is auto-selected by qtype_aitext and must not appear in the quiz UI.
        $this->assertFalse($this->behaviourtype->is_archetypal());
    }

    public function test_can_questions_finish_during_the_attempt(): void {
        // Inherited from deferredfeedback — questions cannot be finished mid-attempt.
        $this->assertFalse($this->behaviourtype->can_questions_finish_during_the_attempt());
    }
}
