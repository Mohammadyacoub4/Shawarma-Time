<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BranchSeeder::class);
        $this->branch = Branch::firstOrFail();
    }

    protected function makeEmployee(array $branchIds = [], bool $inactive = false): User
    {
        $employee = User::factory()->employee()->create([
            'is_active' => ! $inactive,
        ]);

        if (! empty($branchIds)) {
            $employee->branches()->sync($branchIds);
        }

        return $employee;
    }

    public function test_public_registration_is_disabled(): void
    {
        $response = $this->get('/register');
        $this->assertTrue(in_array($response->getStatusCode(), [404, 405]));
    }

    public function test_employee_can_clock_in_successfully(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);

        $response = $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
            'notes' => 'Starting morning shift',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('timesheet_entries', [
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'status' => TimesheetStatus::ClockedIn->value,
            'ends_at' => null,
        ]);

        $this->assertTrue($employee->fresh()->isClockedIn());
    }

    public function test_employee_cannot_clock_in_twice_simultaneously(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);

        // First clock-in
        $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ])->assertSessionHas('status');

        // Second clock-in should be rejected
        $secondResponse = $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ]);

        $secondResponse->assertSessionHas('error');
        $this->assertSame(1, TimesheetEntry::where('user_id', $employee->id)->count());
    }

    public function test_employee_cannot_clock_in_to_unassigned_branch(): void
    {
        $employee = $this->makeEmployee([]); // no branches assigned

        $response = $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('timesheet_entries', [
            'user_id' => $employee->id,
        ]);
    }

    public function test_employee_can_clock_out_successfully_calculates_total_minutes(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);

        // Clock in 3 hours ago
        $clockInTime = now()->subHours(3);
        $entry = TimesheetEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $clockInTime->toDateString(),
            'starts_at' => $clockInTime,
            'ends_at' => null,
            'status' => TimesheetStatus::ClockedIn,
            'total_minutes' => 0,
        ]);

        $this->assertTrue($employee->fresh()->isClockedIn());

        // Clock out
        $response = $this->actingAs($employee)->post(route('employee.attendance.clock-out'), [
            'notes' => 'Completed shift',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $response->assertSessionHas('status');

        $entry->refresh();
        $this->assertNotNull($entry->ends_at);
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
        $this->assertGreaterThanOrEqual(179, $entry->total_minutes);
        $this->assertLessThanOrEqual(181, $entry->total_minutes);
        $this->assertFalse($employee->fresh()->isClockedIn());
    }

    public function test_employee_cannot_clock_out_without_active_clock_in(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);

        $response = $this->actingAs($employee)->post(route('employee.attendance.clock-out'));

        $response->assertSessionHas('error');
    }

    public function test_late_clock_in_is_flagged_when_exceeding_grace_period(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);

        // Create shift scheduled for 09:00 with 15 minutes grace period
        $today = now()->toDateString();
        $shiftStart = Carbon::parse("{$today} 09:00:00");
        $shiftEnd = Carbon::parse("{$today} 17:00:00");

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create();
        Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $today,
            'starts_at' => $shiftStart,
            'ends_at' => $shiftEnd,
            'grace_minutes' => 15,
        ]);

        // Clock in at 09:30 (exceeds 15 min grace period)
        Carbon::setTestNow(Carbon::parse("{$today} 09:30:00"));

        $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ]);

        $entry = TimesheetEntry::where('user_id', $employee->id)->firstOrFail();
        $this->assertTrue($entry->is_late);
        $this->assertSame(30, $entry->late_minutes);

        Carbon::setTestNow(); // Reset test time
    }

    public function test_on_time_clock_in_within_grace_period_is_not_late(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);

        $today = now()->toDateString();
        $shiftStart = Carbon::parse("{$today} 09:00:00");
        $shiftEnd = Carbon::parse("{$today} 17:00:00");

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create();
        Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $today,
            'starts_at' => $shiftStart,
            'ends_at' => $shiftEnd,
            'grace_minutes' => 15,
        ]);

        // Clock in at 09:10 (within 15 min grace period)
        Carbon::setTestNow(Carbon::parse("{$today} 09:10:00"));

        $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ]);

        $entry = TimesheetEntry::where('user_id', $employee->id)->firstOrFail();
        $this->assertFalse($entry->is_late);
        $this->assertSame(0, $entry->late_minutes);

        Carbon::setTestNow();
    }

    public function test_admin_dashboard_shows_live_stats_and_activity(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);

        // Clock in employee
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now(),
            'ends_at' => null,
            'status' => TimesheetStatus::ClockedIn,
            'total_minutes' => 0,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));
        $response->assertOk();
        $response->assertSee($employee->name);
    }

    public function test_admin_can_view_employee_profile_and_attendance_history(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now(),
            'status' => TimesheetStatus::Approved,
            'total_minutes' => 120,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.employees.show', $employee));
        $response->assertOk();
        $response->assertSee($employee->name);
        $response->assertSee('2:00'); // 120 minutes formatted
    }

    public function test_admin_can_toggle_employee_active_status(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);
        $this->assertTrue($employee->is_active);

        // Deactivate
        $this->actingAs($admin)->post(route('admin.employees.toggle-status', $employee))
            ->assertRedirect();
        $this->assertFalse($employee->fresh()->is_active);

        // Reactivate
        $this->actingAs($admin)->post(route('admin.employees.toggle-status', $employee))
            ->assertRedirect();
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_admin_can_filter_reports_and_export_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->subHours(4),
            'ends_at' => now(),
            'status' => TimesheetStatus::Approved,
            'total_minutes' => 240,
            'is_late' => true,
            'late_minutes' => 25,
        ]);

        // Filter by late only
        $response = $this->actingAs($admin)->get(route('admin.reports.index', [
            'late_only' => 1,
        ]));
        $response->assertOk();
        $response->assertSee($employee->name);

        // Export CSV
        $exportCsvResponse = $this->actingAs($admin)->get(route('admin.reports.export', [
            'format' => 'csv',
        ]));
        $exportCsvResponse->assertOk();
        $this->assertTrue(str_contains($exportCsvResponse->headers->get('content-disposition'), '.csv'));

        // Export XLSX
        $exportXlsxResponse = $this->actingAs($admin)->get(route('admin.reports.export', [
            'format' => 'xlsx',
        ]));
        $exportXlsxResponse->assertOk();
        $this->assertTrue(str_contains($exportXlsxResponse->headers->get('content-disposition'), '.xlsx'));
    }

    public function test_admin_can_view_weekly_schedule_calendar(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);

        $response = $this->actingAs($admin)->get(route('admin.schedules.index', [
            'branch_id' => $this->branch->id,
        ]));

        $response->assertOk();
        $response->assertSee('Weekly Schedule Calendar');
    }

    public function test_admin_can_batch_store_shifts_across_multiple_days(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);

        $monday = now()->startOfWeek()->toDateString();
        $tuesday = now()->startOfWeek()->addDay()->toDateString();
        $wednesday = now()->startOfWeek()->addDays(2)->toDateString();

        $response = $this->actingAs($admin)->post(route('admin.schedules.batch-store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $employee->id,
            'days' => [$monday, $tuesday, $wednesday],
            'start_time' => '10:00',
            'end_time' => '18:00',
            'grace_minutes' => 15,
            'notes' => 'Weekly regular shift',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $this->assertSame(3, Shift::where('user_id', $employee->id)->where('branch_id', $this->branch->id)->count());
        $this->assertTrue(Shift::where('user_id', $employee->id)->whereDate('work_date', $monday)->exists());
        $this->assertTrue(Shift::where('user_id', $employee->id)->whereDate('work_date', $wednesday)->exists());
    }

    public function test_admin_publishing_schedule_makes_it_visible_to_all_employees(): void
    {
        $admin = User::factory()->admin()->create();
        $employee1 = $this->makeEmployee([$this->branch->id]);
        $employee2 = $this->makeEmployee([$this->branch->id]);

        $monday = now()->startOfWeek()->toDateString();

        // Admin batch-schedules shifts for both employees
        $this->actingAs($admin)->post(route('admin.schedules.batch-store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $employee1->id,
            'days' => [$monday],
            'start_time' => '09:00',
            'end_time' => '17:00',
            'grace_minutes' => 15,
        ]);

        $this->actingAs($admin)->post(route('admin.schedules.batch-store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $employee2->id,
            'days' => [$monday],
            'start_time' => '12:00',
            'end_time' => '20:00',
            'grace_minutes' => 15,
        ]);

        // Before publishing, employee visits schedule - should see draft / no published shifts
        $this->actingAs($employee1)
            ->get(route('employee.schedules.index', ['branch_id' => $this->branch->id, 'week' => $monday]))
            ->assertOk()
            ->assertSee('No published shifts');

        // Admin publishes the week
        $publishResponse = $this->actingAs($admin)->post(route('admin.schedules.publish'), [
            'branch_id' => $this->branch->id,
            'week_start' => $monday,
        ]);
        $publishResponse->assertRedirect();
        $publishResponse->assertSessionHas('status');

        // Now, employee1 visits schedule - shifts for employee1 and employee2 MUST be visible!
        $emp1Response = $this->actingAs($employee1)
            ->get(route('employee.schedules.index', ['branch_id' => $this->branch->id, 'week' => $monday]));

        $emp1Response->assertOk();
        $emp1Response->assertSee($employee1->name);
        $emp1Response->assertSee($employee2->name);
        $emp1Response->assertSee('You');

        // Employee2 also visits schedule - must see published shifts
        $emp2Response = $this->actingAs($employee2)
            ->get(route('employee.schedules.index', ['branch_id' => $this->branch->id, 'week' => $monday]));

        $emp2Response->assertOk();
        $emp2Response->assertSee($employee1->name);
        $emp2Response->assertSee($employee2->name);
        $emp2Response->assertSee('You');
    }

    public function test_admin_unpublishing_schedule_hides_it_from_employees(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->branch->id]);

        $monday = now()->startOfWeek()->toDateString();

        $this->actingAs($admin)->post(route('admin.schedules.batch-store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $employee->id,
            'days' => [$monday],
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        // Publish
        $this->actingAs($admin)->post(route('admin.schedules.publish'), [
            'branch_id' => $this->branch->id,
            'week_start' => $monday,
        ]);

        // Unpublish
        $this->actingAs($admin)->post(route('admin.schedules.unpublish'), [
            'branch_id' => $this->branch->id,
            'week_start' => $monday,
        ])->assertSessionHas('status');

        // Employee visits schedule - should show No published shifts
        $this->actingAs($employee)
            ->get(route('employee.schedules.index', ['branch_id' => $this->branch->id, 'week' => $monday]))
            ->assertOk()
            ->assertSee('No published shifts');
    }

    public function test_overnight_shift_clock_in_after_midnight_matches_previous_day_shift(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftDate = '2026-11-01'; // Sunday
        $startLocal = Carbon::parse('2026-11-01 22:00:00', $tz);
        $endLocal = Carbon::parse('2026-11-02 06:00:00', $tz);

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create([
            'week_start' => BranchTime::weekStart($shiftDate, $this->branch)->toDateString(),
        ]);

        $shift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $shiftDate,
            'starts_at' => $startLocal->copy()->utc(),
            'ends_at' => $endLocal->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // Clock in Monday at 01:00 (3 hours after Sunday 22:00 start)
        Carbon::setTestNow(Carbon::parse('2026-11-02 01:00:00', $tz)->utc());

        $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ]);

        $entry = TimesheetEntry::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame($shift->id, $entry->shift_id);
        $this->assertSame('2026-11-01', $entry->work_date->toDateString());
        $this->assertTrue($entry->is_late);
        $this->assertSame(180, $entry->late_minutes);

        Carbon::setTestNow();
    }

    public function test_early_clock_in_before_midnight_matches_post_midnight_shift(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftDate = '2026-11-02'; // Monday
        $startLocal = Carbon::parse('2026-11-02 00:30:00', $tz);
        $endLocal = Carbon::parse('2026-11-02 08:30:00', $tz);

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create([
            'week_start' => BranchTime::weekStart($shiftDate, $this->branch)->toDateString(),
        ]);

        $shift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $shiftDate,
            'starts_at' => $startLocal->copy()->utc(),
            'ends_at' => $endLocal->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // Clock in Sunday at 23:45 (45 minutes early, within 60-min window)
        Carbon::setTestNow(Carbon::parse('2026-11-01 23:45:00', $tz)->utc());

        $this->actingAs($employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
        ]);

        $entry = TimesheetEntry::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame($shift->id, $entry->shift_id);
        $this->assertSame('2026-11-02', $entry->work_date->toDateString());
        $this->assertFalse($entry->is_late);
        $this->assertSame(0, $entry->late_minutes);

        Carbon::setTestNow();
    }

    public function test_overnight_shift_clock_in_at_exact_end_and_after_end_boundary(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftDate = '2026-11-01'; // Sunday
        $startLocal = Carbon::parse('2026-11-01 22:00:00', $tz);
        $endLocal = Carbon::parse('2026-11-02 06:00:00', $tz);

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create([
            'week_start' => BranchTime::weekStart($shiftDate, $this->branch)->toDateString(),
        ]);

        $shift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $shiftDate,
            'starts_at' => $startLocal->copy()->utc(),
            'ends_at' => $endLocal->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // 1. Exactly at end time (06:00:00) -> must match
        Carbon::setTestNow($endLocal->copy()->utc());
        $service = app(\App\Services\TimesheetService::class);
        $entryExact = $service->clockIn($employee, $this->branch->id);

        $this->assertSame($shift->id, $entryExact->shift_id);
        $this->assertSame('2026-11-01', $entryExact->work_date->toDateString());

        // Clock out and delete entry to reset
        $service->clockOut($employee);
        $entryExact->delete();

        // 2. 1 second after end time (06:00:01) -> must NOT match shift (remains unlinked unscheduled)
        Carbon::setTestNow($endLocal->copy()->addSecond()->utc());
        $entryPast = $service->clockIn($employee, $this->branch->id);

        $this->assertNull($entryPast->shift_id);
        $this->assertSame('2026-11-02', $entryPast->work_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_overnight_shift_clock_out_calculates_minutes_and_preserves_shift_work_date(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftDate = '2026-11-01'; // Sunday
        $startLocal = Carbon::parse('2026-11-01 22:00:00', $tz);
        $endLocal = Carbon::parse('2026-11-02 06:00:00', $tz);

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create([
            'week_start' => BranchTime::weekStart($shiftDate, $this->branch)->toDateString(),
        ]);

        $shift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $shiftDate,
            'starts_at' => $startLocal->copy()->utc(),
            'ends_at' => $endLocal->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // Clock in Monday at 01:00
        Carbon::setTestNow(Carbon::parse('2026-11-02 01:00:00', $tz)->utc());
        $service = app(\App\Services\TimesheetService::class);
        $entry = $service->clockIn($employee, $this->branch->id, 'Late start on graveyard');

        $this->assertSame($shift->id, $entry->shift_id);
        $this->assertSame('2026-11-01', $entry->work_date->toDateString());

        // Clock out Monday at 06:00 (5 hours = 300 minutes)
        Carbon::setTestNow(Carbon::parse('2026-11-02 06:00:00', $tz)->utc());
        $this->actingAs($employee)->post(route('employee.attendance.clock-out'), [
            'notes' => 'Shift finished',
        ]);

        $fresh = $entry->fresh();
        $this->assertSame(TimesheetStatus::PendingApproval, $fresh->status);
        $this->assertSame(300, $fresh->total_minutes);
        $this->assertSame($shift->id, $fresh->shift_id);
        $this->assertSame('2026-11-01', $fresh->work_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_existing_rejected_timesheet_prevents_reusing_same_shift_during_clock_in(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $today = '2026-11-02';
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftStart = Carbon::parse("{$today} 09:00:00", $tz);
        $shiftEnd = Carbon::parse("{$today} 17:00:00", $tz);

        $week = ScheduleWeek::factory()->for($this->branch)->published()->create();
        $shift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $today,
            'starts_at' => $shiftStart->copy()->utc(),
            'ends_at' => $shiftEnd->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // Pre-existing rejected entry for this shift
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'shift_id' => $shift->id,
            'work_date' => $today,
            'starts_at' => $shiftStart->copy()->utc(),
            'ends_at' => $shiftEnd->copy()->utc(),
            'total_minutes' => 480,
            'status' => TimesheetStatus::Rejected,
        ]);

        // Clock in at 09:00 - shift has a timesheet entry, so it must not be linked
        Carbon::setTestNow($shiftStart->copy()->utc());
        $service = app(\App\Services\TimesheetService::class);
        $entry = $service->clockIn($employee, $this->branch->id);

        $this->assertNull($entry->shift_id);
        $this->assertSame($today, $entry->work_date->toDateString());

        Carbon::setTestNow();
    }

    public function test_clock_in_does_not_associate_unpublished_draft_shift(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $today = '2026-11-02';
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftStart = Carbon::parse("{$today} 09:00:00", $tz);
        $shiftEnd = Carbon::parse("{$today} 17:00:00", $tz);

        // Create an UNPUBLISHED draft schedule week
        $draftWeek = ScheduleWeek::factory()->for($this->branch)->create([
            'week_start' => BranchTime::weekStart($shiftStart, $this->branch)->toDateString(),
            'is_published' => false,
        ]);

        $draftShift = Shift::create([
            'schedule_week_id' => $draftWeek->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $today,
            'starts_at' => $shiftStart->copy()->utc(),
            'ends_at' => $shiftEnd->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // Clock in at 09:00 - shift belongs to an unpublished draft week, so it must not be linked
        Carbon::setTestNow($shiftStart->copy()->utc());
        $service = app(\App\Services\TimesheetService::class);
        $entry = $service->clockIn($employee, $this->branch->id);

        $this->assertNull($entry->shift_id);
        $this->assertSame($today, $entry->work_date->toDateString());
        $this->assertEquals(TimesheetStatus::ClockedIn, $entry->status);

        Carbon::setTestNow();
    }

    public function test_clock_in_associates_published_shift(): void
    {
        $employee = $this->makeEmployee([$this->branch->id]);
        $today = '2026-11-02';
        $tz = BranchTime::timezoneFor($this->branch);

        $shiftStart = Carbon::parse("{$today} 09:00:00", $tz);
        $shiftEnd = Carbon::parse("{$today} 17:00:00", $tz);

        // Create a PUBLISHED schedule week
        $publishedWeek = ScheduleWeek::factory()->for($this->branch)->published()->create([
            'week_start' => BranchTime::weekStart($shiftStart, $this->branch)->toDateString(),
        ]);

        $publishedShift = Shift::create([
            'schedule_week_id' => $publishedWeek->id,
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => $today,
            'starts_at' => $shiftStart->copy()->utc(),
            'ends_at' => $shiftEnd->copy()->utc(),
            'grace_minutes' => 15,
        ]);

        // Clock in at 09:00 - shift belongs to a published week, so it must be linked
        Carbon::setTestNow($shiftStart->copy()->utc());
        $service = app(\App\Services\TimesheetService::class);
        $entry = $service->clockIn($employee, $this->branch->id);

        $this->assertSame($publishedShift->id, $entry->shift_id);
        $this->assertSame($today, $entry->work_date->toDateString());
        $this->assertEquals(TimesheetStatus::ClockedIn, $entry->status);

        Carbon::setTestNow();
    }
}
