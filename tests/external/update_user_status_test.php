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
 * External function tests for attendance update_user_status.
 *
 * @package    mod_attendance
 * @category   test
 * @copyright  2026 Dan Marsden
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_attendance\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/base_external_testcase.php');
require_once(__DIR__ . '/../../locallib.php');

use core_external\external_api;

/**
 * External function tests for attendance update_user_status.
 *
 * @group mod_attendance
 */
final class update_user_status_test extends base_external_testcase {
    /**
     * Test update_user_status::execute updates a student's attendance status.
     *
     * @covers \mod_attendance\external\update_user_status::execute
     */
    public function test_execute_updates_status(): void {
        $this->resetAfterTest(true);

        $context = $this->create_attendance_context(4);
        $this->setUser($context['teacher']);
        $session = $this->create_session($context['attendance']);

        $sessioninfo = get_session::execute($session->id);
        $sessioninfo = external_api::clean_returnvalue(get_session::execute_returns(), $sessioninfo);

        $student = reset($sessioninfo['users']);
        $status = reset($sessioninfo['statuses']);

        $result = update_user_status::execute(
            $session->id,
            $student['id'],
            $context['teacher']->id,
            $status['id'],
            $sessioninfo['statusset']
        );
        $result = external_api::clean_returnvalue(update_user_status::execute_returns(), $result);

        $this->assertIsString($result);
        $this->assertNotSame('', trim($result));
    }

    /**
     * Test update_user_status::execute rejects updates to another student.
     *
     * @covers \mod_attendance\external\update_user_status::execute
     */
    public function test_execute_disallows_other_students(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('studentscanmark', 1, 'attendance');

        $context = $this->create_attendance_context(3);
        $this->setUser($context['teacher']);
        $session = $this->create_session($context['attendance']);
        $DB->set_field('attendance_sessions', 'studentscanmark', 1, ['id' => $session->id]);

        $sessioninfo = get_session::execute($session->id);
        $sessioninfo = external_api::clean_returnvalue(get_session::execute_returns(), $sessioninfo);
        $status = reset($sessioninfo['statuses']);

        $attacker = $context['students'][0];
        $victim = $context['students'][1];

        $this->setUser($attacker);
        $this->expectException(\invalid_parameter_exception::class);
        update_user_status::execute($session->id, $victim->id, $victim->id, $status['id'], $sessioninfo['statusset']);
    }

    /**
     * Test update_user_status::execute validates statusset.
     *
     * @covers \mod_attendance\external\update_user_status::execute
     */
    public function test_execute_invalid_statusset(): void {
        $this->resetAfterTest(true);

        $context = $this->create_attendance_context(2);
        $this->setUser($context['teacher']);
        $session = $this->create_session($context['attendance']);

        $sessioninfo = get_session::execute($session->id);
        $sessioninfo = external_api::clean_returnvalue(get_session::execute_returns(), $sessioninfo);

        $student = reset($sessioninfo['users']);
        $status = reset($sessioninfo['statuses']);

        $this->expectException(\invalid_parameter_exception::class);
        update_user_status::execute(
            $session->id,
            $student['id'],
            $context['teacher']->id,
            $status['id'],
            $sessioninfo['statusset'] + 1
        );
    }

    /**
     * Test update_user_status::execute validates status id.
     *
     * @covers \mod_attendance\external\update_user_status::execute
     */
    public function test_execute_invalid_statusid(): void {
        $this->resetAfterTest(true);

        $context = $this->create_attendance_context(2);
        $this->setUser($context['teacher']);
        $session = $this->create_session($context['attendance']);

        $sessioninfo = get_session::execute($session->id);
        $sessioninfo = external_api::clean_returnvalue(get_session::execute_returns(), $sessioninfo);
        $student = reset($sessioninfo['users']);

        $this->expectException(\invalid_parameter_exception::class);
        update_user_status::execute(
            $session->id,
            $student['id'],
            $context['teacher']->id,
            999999999,
            $sessioninfo['statusset']
        );
    }

    /**
     * Test a self-marking student can use a status belonging to the session's own status set.
     *
     * @covers \mod_attendance\external\update_user_status::execute
     */
    public function test_execute_uses_the_sessions_status_set(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('studentscanmark', 1, 'attendance');

        $context = $this->create_attendance_context(1);
        $attendance = $context['attendance'];
        $this->setUser($context['teacher']);
        // The self-marking window opens only once the clock is strictly past sessdate, so
        // anchor it in the past to leave the window unambiguously open.
        $session = $this->create_session($attendance, ['sessdate' => time() - MINSECS]);
        $DB->set_field('attendance_sessions', 'studentscanmark', 1, ['id' => $session->id]);
        // Every status the generator ships lives in set 0, so move the session onto a different
        // set and give that set a status of its own. A student marking themselves must be
        // offered that status, not the ones belonging to set 0.
        $DB->set_field('attendance_sessions', 'statusset', 1, ['id' => $session->id]);

        $status = new \stdClass();
        $status->attendanceid = $attendance->id;
        $status->acronym = 'P1';
        $status->description = 'Present in set 1';
        $status->grade = 1;
        $status->setnumber = 1;
        // Declared so the event snapshot attendance_add_status() takes matches the table's
        // column list; left null so the status stays available for the whole session.
        $status->studentavailability = null;
        $this->assertTrue(attendance_add_status($status));

        // Control: the new status belongs to set 1 and to no other, so a pass below cannot
        // come from the two sets happening to share an id.
        $this->assertArrayHasKey((int)$status->id, attendance_get_statuses($attendance->id, true, 1));
        $this->assertArrayNotHasKey((int)$status->id, attendance_get_statuses($attendance->id, true, 0));

        $student = $context['students'][0];
        $this->setUser($student);

        $result = update_user_status::execute($session->id, $student->id, $student->id, (int)$status->id, 1);
        external_api::clean_returnvalue(update_user_status::execute_returns(), $result);

        $log = $DB->get_record(
            'attendance_log',
            ['sessionid' => $session->id, 'studentid' => $student->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals((int)$status->id, (int)$log->statusid);
        $this->assertEquals(1, (int)$log->statusset);
    }

    /**
     * Test the web service refuses a re-mark when the session disallows status updates.
     *
     * @covers \mod_attendance\external\update_user_status::execute
     */
    public function test_execute_honours_allowupdatestatus(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('studentscanmark', 1, 'attendance');

        $context = $this->create_attendance_context(1);
        $attendance = $context['attendance'];
        $this->setUser($context['teacher']);
        $session = $this->create_session($attendance, ['sessdate' => time() - MINSECS]);
        $DB->set_field('attendance_sessions', 'studentscanmark', 1, ['id' => $session->id]);

        $statuses = attendance_get_statuses($attendance->id, true, 0);
        $this->assertNotEmpty($statuses);
        $first = reset($statuses);
        $second = next($statuses);
        $this->assertNotFalse($second, 'The generator must offer two statuses to switch between.');

        $student = $context['students'][0];
        $this->setUser($student);

        // First mark: nothing recorded yet, so the guard cannot apply.
        update_user_status::execute($session->id, $student->id, $student->id, (int)$first->id, 0);

        // Control: with updates allowed the second mark goes through, which is what proves
        // the rejection below comes from the guard and not from re-marking being impossible.
        $DB->set_field('attendance_sessions', 'allowupdatestatus', 1, ['id' => $session->id]);
        update_user_status::execute($session->id, $student->id, $student->id, (int)$second->id, 0);
        $log = $DB->get_record(
            'attendance_log',
            ['sessionid' => $session->id, 'studentid' => $student->id],
            '*',
            MUST_EXIST
        );
        $this->assertEquals((int)$second->id, (int)$log->statusid);

        // With updates disallowed the same call must be refused.
        $DB->set_field('attendance_sessions', 'allowupdatestatus', 0, ['id' => $session->id]);
        $this->expectException(\invalid_parameter_exception::class);
        update_user_status::execute($session->id, $student->id, $student->id, (int)$first->id, 0);
    }
}
