<?php

namespace Tests\Feature\Admin;

use App\Enums\TimesheetStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardAbsenceDetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee;
    private Branch $branchA;
    private Branch $branchB;
    private ScheduleWeek $weekA;
    private ScheduleWeek $weekB;
    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        $this->branchA = Branch::factory()->create([
            'name' => 'Downtown Branch',
            'timezone' => 'Canada/Pacific',
            'grace_minutes' => 15,
        ]);
        $this->branchB = Branch::factory()->create([
            'name' => 'Uptown Branch',
            'timezone' => 'Canada/Pacific',
            'grace_minutes' => 15,
        ]);

        $this->employee = User::factory()->create([
            'role' => UserRole::Employee,
            'is_active' => true,
        ]);
        $this->employee->branches()->attach([$this->branchA->id, $this->branchB->id]);

        $this->today = '2026-05-13'; // Fixed reference Wednesday
        Carbon::setTestNow(Carbon::parse("{$this->today} 12:00:00", 'UTC'));

        $this->weekA = ScheduleWeek::factory()->for($this->branchA)->published()->create([
            'week_start' => '2026-05-11',
        ]);
        $this->weekB = ScheduleWeek::factory()->for($this->branchB)->published()->create([
            'week_start' => '2026-05-11',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 1. Clock-in within 60 minutes links to the correct shift.
     */
    public function test_clock_in_links_shift_within_60_minutes_of_start(): void
    {
        $shift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 09:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 17:00:00", 'UTC'),
            'grace_minutes' => 15,
        ]);

        // Clock in at 08:15 (45 mins early, within 60 min window)
        Carbon::setTestNow(Carbon::parse("{$this->today} 08:15:00", 'UTC'));

        $service = app(TimesheetService::class);
        $entry = $service->clockIn($this->employee, $this->branchA->id);

        $this->assertSame($shift->id, $entry->shift_id);
    }

    /**
     * 2. Clock-in more than 60 minutes early remains unlinked (shift_id = null).
     */
    public function test_clock_in_does_not_link_shift_more_than_60_minutes_early(): void
    {
        $shift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 09:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 17:00:00", 'UTC'),
            'grace_minutes' => 15,
        ]);

        // Clock in at 07:45 (75 mins early, > 60 mins)
        Carbon::setTestNow(Carbon::parse("{$this->today} 07:45:00", 'UTC'));

        $service = app(TimesheetService::class);
        $entry = $service->clockIn($this->employee, $this->branchA->id);

        $this->assertNull($entry->shift_id);
    }

    /**
     * Boundary tests for 60-minute window:
     * - Exactly at 60 minutes before start
     * - 1 second earlier than 60 minutes
     * - Exactly at start time
     * - Exactly at end time
     */
    public function test_clock_in_early_window_boundary_conditions(): void
    {
        $shift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 10:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
            'grace_minutes' => 15,
        ]);

        $service = app(TimesheetService::class);

        // 1 second too early (08:59:59) -> must remain unlinked
        Carbon::setTestNow(Carbon::parse("{$this->today} 08:59:59", 'UTC'));
        $entryEarly = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertNull($entryEarly->shift_id);

        // Clock out and delete unlinked entry to reset
        $service->clockOut($this->employee);
        $entryEarly->delete();

        // Exactly 60 minutes early (09:00:00) -> must link
        Carbon::setTestNow(Carbon::parse("{$this->today} 09:00:00", 'UTC'));
        $entryExact60 = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertSame($shift->id, $entryExact60->shift_id);
    }

    public function test_clock_in_at_exact_start_and_end_boundaries(): void
    {
        $shift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 10:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
            'grace_minutes' => 15,
        ]);

        $service = app(TimesheetService::class);

        // Exactly at start time (10:00:00) -> must link
        Carbon::setTestNow(Carbon::parse("{$this->today} 10:00:00", 'UTC'));
        $entryStart = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertSame($shift->id, $entryStart->shift_id);

        $service->clockOut($this->employee);
        $entryStart->delete();

        // Exactly at end time (18:00:00) -> must link
        Carbon::setTestNow(Carbon::parse("{$this->today} 18:00:00", 'UTC'));
        $entryEnd = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertSame($shift->id, $entryEnd->shift_id);

        $service->clockOut($this->employee);
        $entryEnd->delete();

        // 1 second after end time (18:00:01) -> must NOT link
        Carbon::setTestNow(Carbon::parse("{$this->today} 18:00:01", 'UTC'));
        $entryPast = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertNull($entryPast->shift_id);
    }

    /**
     * 3. Clock-in skips a shift with a rejected entry and avoids unique constraint violation.
     */
    public function test_clock_in_skips_shift_with_rejected_entry_and_selects_next_eligible_shift(): void
    {
        $shift1 = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        $shift2 = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 14:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
        ]);

        // Shift 1 has an existing REJECTED timesheet entry
        TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => $shift1->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
            'total_minutes' => 240,
            'status' => TimesheetStatus::Rejected,
        ]);

        // Clock in at 13:45 for Shift 2 (within 60 min window of Shift 2)
        Carbon::setTestNow(Carbon::parse("{$this->today} 13:45:00", 'UTC'));

        $service = app(TimesheetService::class);
        $entry = $service->clockIn($this->employee, $this->branchA->id);

        // Must successfully link Shift 2 without crashing with SQL Duplicate entry
        $this->assertSame($shift2->id, $entry->shift_id);
    }

    /**
     * 4. Clock-in selects second shift when it is within the allowed window.
     * 5. Clock-in after completing first shift selects the next eligible shift.
     */
    public function test_clock_in_selects_second_shift_after_completing_first(): void
    {
        $shift1 = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        $shift2 = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 17:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 21:00:00", 'UTC'),
        ]);

        $service = app(TimesheetService::class);

        // 1. Clock in and clock out for Shift 1
        Carbon::setTestNow(Carbon::parse("{$this->today} 08:05:00", 'UTC'));
        $entry1 = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertSame($shift1->id, $entry1->shift_id);

        Carbon::setTestNow(Carbon::parse("{$this->today} 12:00:00", 'UTC'));
        $service->clockOut($this->employee);

        // 2. Clock in at 14:00 (more than 60 mins before Shift 2) -> unlinked
        Carbon::setTestNow(Carbon::parse("{$this->today} 14:00:00", 'UTC'));
        $entryMid = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertNull($entryMid->shift_id);
        $service->clockOut($this->employee);

        // 3. Clock in at 16:30 (within 60 mins before Shift 2) -> must link Shift 2
        Carbon::setTestNow(Carbon::parse("{$this->today} 16:30:00", 'UTC'));
        $entry2 = $service->clockIn($this->employee, $this->branchA->id);
        $this->assertSame($shift2->id, $entry2->shift_id);
    }

    /**
     * 6. Open early clock-in satisfies its matching morning shift.
     * 7. Open morning clock-in does not suppress evening shift absence.
     */
    public function test_open_morning_clock_in_does_not_suppress_evening_shift_absence(): void
    {
        $morningShift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        $eveningShift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 17:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 21:00:00", 'UTC'),
        ]);

        // Employee clocked in 5 minutes early at 07:55 for morning shift (legacy entry with shift_id = null)
        // and forgot to clock out (ends_at = null)
        TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => null,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 07:55:00", 'UTC'),
            'ends_at' => null,
            'status' => TimesheetStatus::ClockedIn,
            'total_minutes' => 0,
        ]);

        // Current time is 18:00 (evening shift start has passed)
        Carbon::setTestNow(Carbon::parse("{$this->today} 18:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');

        // Morning shift must NOT be absent (satisfied by the open entry within early window)
        $this->assertFalse($absentShifts->contains('id', $morningShift->id));

        // Evening shift MUST be absent (open morning punch does not suppress it)
        $this->assertTrue($absentShifts->contains('id', $eveningShift->id));
    }

    /**
     * 8. One extended legacy entry does not satisfy multiple shifts.
     */
    public function test_legacy_extended_entry_does_not_double_count_across_two_scheduled_shifts(): void
    {
        $shift1 = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        $shift2 = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 17:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 21:00:00", 'UTC'),
        ]);

        // A single legacy closed entry from 08:00 to 18:00 (overlaps shift 1 and overlaps shift 2)
        TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => null,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
            'total_minutes' => 600,
            'status' => TimesheetStatus::Approved,
        ]);

        Carbon::setTestNow(Carbon::parse("{$this->today} 19:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');

        // Shift 1 was satisfied by the 08:00 punch
        $this->assertFalse($absentShifts->contains('id', $shift1->id));

        // Shift 2 MUST be absent because the punch already belonged to earlier Shift 1
        $this->assertTrue($absentShifts->contains('id', $shift2->id));
    }

    /**
     * 9. Earlier shift at another branch does not block attendance.
     */
    public function test_earlier_shift_at_different_branch_does_not_block_current_shift_attendance(): void
    {
        // Shift 1 at Branch B: 08:00 - 12:00
        $shiftBranchB = Shift::create([
            'schedule_week_id' => $this->weekB->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchB->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        // Shift 2 at Branch A: 14:00 - 18:00
        $shiftBranchA = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 14:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
        ]);

        // Employee worked at Branch A from 14:00 to 18:00 (unlinked legacy entry)
        TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => null,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 14:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
            'total_minutes' => 240,
            'status' => TimesheetStatus::Approved,
        ]);

        Carbon::setTestNow(Carbon::parse("{$this->today} 19:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');

        // Shift at Branch A is attended (not blocked by the earlier shift at Branch B)
        $this->assertFalse($absentShifts->contains('id', $shiftBranchA->id));

        // Shift at Branch B had no punch -> must be absent
        $this->assertTrue($absentShifts->contains('id', $shiftBranchB->id));
    }

    /**
     * 10. Earlier unpublished shift does not block attendance.
     */
    public function test_earlier_unpublished_shift_does_not_block_current_shift_attendance(): void
    {
        // A shift without schedule_week_id (or on unpublished week)
        $draftMorningShift = Shift::create([
            'schedule_week_id' => null,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        // Published afternoon shift 14:00 - 18:00
        $publishedShift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 14:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
        ]);

        // Attendance entry for afternoon shift
        TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => null,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 14:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 18:00:00", 'UTC'),
            'total_minutes' => 240,
            'status' => TimesheetStatus::Approved,
        ]);

        Carbon::setTestNow(Carbon::parse("{$this->today} 19:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');

        // The draft morning shift must NOT appear in absence list because week is unpublished
        $this->assertFalse($absentShifts->contains('id', $draftMorningShift->id));

        // Published shift is attended (the draft earlier shift does not block attendance)
        $this->assertFalse($absentShifts->contains('id', $publishedShift->id));
    }

    /**
     * 11. Mismatched direct-link user or branch does not clear absence.
     */
    public function test_mismatched_direct_link_does_not_clear_absence(): void
    {
        $otherEmployee = User::factory()->create(['role' => UserRole::Employee]);
        $otherEmployee->branches()->attach($this->branchA->id);

        $shift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        // A corrupted/mismatched direct entry pointing to $shift->id but belonging to $otherEmployee
        TimesheetEntry::create([
            'user_id' => $otherEmployee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => $shift->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
            'total_minutes' => 240,
            'status' => TimesheetStatus::Approved,
        ]);

        Carbon::setTestNow(Carbon::parse("{$this->today} 13:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');

        // $shift belongs to $this->employee, who didn't work it -> must be flagged absent
        $this->assertTrue($absentShifts->contains('id', $shift->id));
    }

    /**
     * 12. Rejected timesheet leaves the shift flagged absent.
     */
    public function test_rejected_timesheet_leaves_shift_flagged_absent(): void
    {
        $shift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'shift_id' => $shift->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
            'total_minutes' => 240,
            'status' => TimesheetStatus::Rejected,
            'rejection_reason' => 'Invalid punch',
        ]);

        Carbon::setTestNow(Carbon::parse("{$this->today} 13:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');
        $this->assertTrue($absentShifts->contains('id', $shift->id));
    }

    /**
     * 13. Future shifts today are not flagged absent before their start.
     */
    public function test_future_shifts_today_are_not_flagged_absent_before_start_time(): void
    {
        $pastShift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 12:00:00", 'UTC'),
        ]);

        $futureShift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $this->today,
            'starts_at' => Carbon::parse("{$this->today} 16:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$this->today} 20:00:00", 'UTC'),
        ]);

        // Current time is 13:00 (pastShift start has passed; futureShift start is in the future)
        Carbon::setTestNow(Carbon::parse("{$this->today} 13:00:00", 'UTC'));

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $this->today]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');

        $this->assertTrue($absentShifts->contains('id', $pastShift->id));
        $this->assertFalse($absentShifts->contains('id', $futureShift->id));
    }

    /**
     * 14. Past unattended published shifts are flagged absent.
     */
    public function test_past_unattended_published_shifts_are_flagged_absent(): void
    {
        $pastDate = '2026-05-11'; // Monday of same week

        $pastShift = Shift::create([
            'schedule_week_id' => $this->weekA->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $pastDate,
            'starts_at' => Carbon::parse("{$pastDate} 08:00:00", 'UTC'),
            'ends_at' => Carbon::parse("{$pastDate} 16:00:00", 'UTC'),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $pastDate]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');
        $this->assertTrue($absentShifts->contains('id', $pastShift->id));
    }

    /**
     * 15. Clock in after midnight for yesterday's overnight shift clears absence on previous day.
     */
    public function test_overnight_clock_in_after_midnight_clears_previous_day_shift_absence(): void
    {
        $tz = BranchTime::timezoneFor($this->branchA);
        $sunday = '2026-05-10';
        $monday = '2026-05-11';

        $week = ScheduleWeek::factory()->for($this->branchA)->published()->create([
            'week_start' => $sunday,
        ]);

        $overnightShift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $this->employee->id,
            'branch_id' => $this->branchA->id,
            'work_date' => $sunday,
            'starts_at' => Carbon::parse("{$sunday} 22:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$monday} 06:00:00", $tz)->utc(),
            'grace_minutes' => 15,
        ]);

        // Clock in Monday at 01:00
        Carbon::setTestNow(Carbon::parse("{$monday} 01:00:00", $tz)->utc());

        $service = app(TimesheetService::class);
        $entry = $service->clockIn($this->employee, $this->branchA->id);

        $this->assertSame($overnightShift->id, $entry->shift_id);
        $this->assertSame($sunday, $entry->work_date->toDateString());

        // Check Sunday's dashboard: shift must NOT be flagged absent
        $response = $this->actingAs($this->admin)->get(route('admin.dashboard', ['date' => $sunday]));
        $response->assertOk();

        $absentShifts = $response->viewData('absentShifts');
        $this->assertFalse($absentShifts->contains('id', $overnightShift->id));

        Carbon::setTestNow();
    }
}
