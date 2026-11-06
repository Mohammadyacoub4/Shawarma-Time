<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetMinutePrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $employee;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->branch = Branch::firstOrFail();

        $this->admin = User::factory()->admin()->create();

        $this->employee = User::factory()->employee()->create();
        $this->employee->branches()->sync([$this->branch->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 1. Employee can manually submit 08:07 to 16:53 with minute-level precision */
    public function test_employee_can_manually_submit_minute_precision_times(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00'); // Branch local: 2026-11-02 13:00:00

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '16:53',
            'employee_notes' => 'Custom minute punch',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $response->assertSessionHas('status');

        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
        $this->assertEquals(526, $entry->total_minutes); // 8h 46m = 526m

        $tz = BranchTime::timezoneFor($this->branch);
        $this->assertEquals('08:07', $entry->starts_at->timezone($tz)->format('H:i'));
        $this->assertEquals('16:53', $entry->ends_at->timezone($tz)->format('H:i'));
    }

    /** 2. Admin can edit arbitrary valid minutes on an existing timesheet */
    public function test_admin_can_edit_arbitrary_valid_minutes(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->branch)->create([
            'work_date' => '2026-11-01',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '09:00', $this->branch)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '17:00', $this->branch)),
            'total_minutes' => 480,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:02',
            'end_time' => '16:58',
            'admin_notes' => 'Adjusted to exact minutes',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Timesheet updated.');

        $entry->refresh();
        $tz = BranchTime::timezoneFor($this->branch);
        $this->assertEquals('08:02', $entry->starts_at->timezone($tz)->format('H:i'));
        $this->assertEquals('16:58', $entry->ends_at->timezone($tz)->format('H:i'));
        $this->assertEquals(536, $entry->total_minutes); // 8h 56m = 536m
    }

    /** 3. Admin can edit unrounded live clock-in/out entries without 15-minute error */
    public function test_admin_can_edit_clock_in_out_generated_entry_without_15_minute_increment_error(): void
    {
        // Simulate live clock-in and clock-out with exact seconds/minutes
        $startUtc = Carbon::parse('2026-11-01 15:07:23'); // Local 08:07:23
        $endUtc = Carbon::parse('2026-11-01 23:53:41');   // Local 16:53:41

        $entry = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
            'total_minutes' => (int) $startUtc->diffInMinutes($endUtc),
            'status' => TimesheetStatus::PendingApproval,
            'submitted_at' => $endUtc,
        ]);

        // Admin updates end_time to 17:00 while leaving start_time at 08:07
        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '17:00',
            'admin_notes' => 'Adjusted end punch',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', 'Timesheet updated.');

        $entry->refresh();
        $tz = BranchTime::timezoneFor($this->branch);
        $this->assertEquals('08:07', $entry->starts_at->timezone($tz)->format('H:i'));
        $this->assertEquals('17:00', $entry->ends_at->timezone($tz)->format('H:i'));
        $this->assertEquals(533, $entry->total_minutes); // 8h 53m = 533m
    }

    /** 4. Scheduled shifts still reject non-15-minute increments like 08:20 */
    public function test_scheduled_shifts_still_reject_non_15_minute_increments(): void
    {
        $week = ScheduleWeek::factory()->for($this->branch)->create(['is_published' => false]);

        // Attempt to create shift with non-15-minute start_time (08:20)
        $responseStart = $this->actingAs($this->admin)->post(route('admin.schedules.shifts.store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'work_date' => '2026-11-03',
            'start_time' => '08:20',
            'end_time' => '17:00',
        ]);

        $responseStart->assertSessionHasErrors('start_time');
        $this->assertEquals(0, Shift::count());

        // Attempt to create shift with non-15-minute end_time (17:10)
        $responseEnd = $this->actingAs($this->admin)->post(route('admin.schedules.shifts.store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'work_date' => '2026-11-03',
            'start_time' => '09:00',
            'end_time' => '17:10',
        ]);

        $responseEnd->assertSessionHasErrors('end_time');
        $this->assertEquals(0, Shift::count());

        // Valid 15-minute shift succeeds
        $responseValid = $this->actingAs($this->admin)->post(route('admin.schedules.shifts.store'), [
            'branch_id' => $this->branch->id,
            'user_id' => $this->employee->id,
            'work_date' => '2026-11-03',
            'start_time' => '08:45',
            'end_time' => '17:15',
        ]);

        $responseValid->assertSessionHasNoErrors();
        $this->assertEquals(1, Shift::count());
    }

    /** 5. Invalid time formats are strictly rejected for timesheets */
    public function test_invalid_time_formats_are_rejected_for_timesheets(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        // Invalid hour 25:00
        $response1 = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '25:00',
            'end_time' => '17:00',
        ]);
        $response1->assertSessionHasErrors('start_time');

        // Invalid minute 12:60
        $response2 = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '12:60',
        ]);
        $response2->assertSessionHasErrors('end_time');
    }

    /** 6. Overnight minute-precision entries are supported */
    public function test_overnight_minute_precision_timesheet_entries_work(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '22:07',
            'end_time' => '06:13',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals(486, $entry->total_minutes); // 8h 6m = 486m
    }

    /** 7. Equal start and end time is rejected */
    public function test_equal_start_and_end_time_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '08:07',
        ]);

        $response->assertSessionHasErrors('end_time');
    }

    /** 8. Overlap validation works with minute precision */
    public function test_overlap_validation_applies_with_minute_precision(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        // Existing entry: 08:07 to 16:53
        $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '16:53',
        ]);

        // Attempt overlapping entry: 16:50 to 20:00 (overlaps by 3 minutes)
        $responseOverlap = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '16:50',
            'end_time' => '20:00',
        ]);

        $responseOverlap->assertSessionHasErrors('start_time');

        // Non-overlapping adjacent entry starting at 16:53 succeeds
        $responseAdjacent = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '16:53',
            'end_time' => '20:00',
        ]);

        $responseAdjacent->assertRedirect(route('employee.dashboard'));
        $this->assertEquals(2, TimesheetEntry::where('user_id', $this->employee->id)->count());
    }

    /** 9. Audit trail logs minute-precision changes */
    public function test_audit_trail_logs_minute_precision_changes(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->branch)->create([
            'work_date' => '2026-11-01',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '08:07', $this->branch)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '16:53', $this->branch)),
            'total_minutes' => 526,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'admin_notes' => 'Adjusted to shift boundaries',
        ]);

        $log = TimesheetAuditLog::where('timesheet_entry_id', $entry->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin_time_adjusted', $log->action);
        $this->assertEquals('Adjusted to shift boundaries', $log->notes);
    }

    /** 10. Approved attendance entry with seconds retains Approved status, review metadata, and exact seconds on notes-only edit */
    public function test_approved_attendance_entry_with_seconds_retains_approved_status_and_exact_seconds_on_notes_edit(): void
    {
        $startUtc = Carbon::parse('2026-11-01 15:07:23'); // Branch local (UTC-7): 08:07:23
        $endUtc = Carbon::parse('2026-11-01 23:53:41');   // Branch local (UTC-7): 16:53:41
        $reviewedAt = Carbon::parse('2026-11-02 01:00:00');

        $entry = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
            'total_minutes' => (int) $startUtc->diffInMinutes($endUtc),
            'status' => TimesheetStatus::Approved,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => $reviewedAt,
            'submitted_at' => $endUtc,
            'employee_notes' => 'Original employee note',
        ]);

        // Admin form submits the wall-clock H:i (08:07 - 16:53) without seconds, editing only notes
        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '16:53',
            'admin_notes' => 'Reviewed and approved by GM',
            'employee_notes' => 'Updated employee note by admin',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', 'Timesheet updated.');

        $entry->refresh();

        // 1. Approved status retained
        $this->assertEquals(TimesheetStatus::Approved, $entry->status);

        // 2. reviewed_by and reviewed_at remain unchanged
        $this->assertEquals($this->admin->id, $entry->reviewed_by);
        $this->assertEquals($reviewedAt->toDateTimeString(), $entry->reviewed_at?->toDateTimeString());

        // 4. Stored timestamps retain exact punch seconds
        $this->assertEquals('2026-11-01 15:07:23', $entry->starts_at->toDateTimeString());
        $this->assertEquals('2026-11-01 23:53:41', $entry->ends_at->toDateTimeString());
        $this->assertEquals('23', $entry->starts_at->format('s'));
        $this->assertEquals('41', $entry->ends_at->format('s'));

        // Notes updated and audit log marked as metadata-only update
        $this->assertEquals('Updated employee note by admin', $entry->employee_notes);
        $log = TimesheetAuditLog::where('timesheet_entry_id', $entry->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin_metadata_updated', $log->action);
        $this->assertEquals('Reviewed and approved by GM', $log->notes);
    }

    /** 11. Changing actual start or end minute still resets Approved entries to PendingApproval and clears review metadata */
    public function test_changing_actual_start_or_end_minute_resets_approved_entry_to_pending_and_clears_review_metadata(): void
    {
        $startUtc = Carbon::parse('2026-11-01 15:07:23'); // Local 08:07:23
        $endUtc = Carbon::parse('2026-11-01 23:53:41');   // Local 16:53:41
        $reviewedAt = Carbon::parse('2026-11-02 01:00:00');

        $entry = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
            'total_minutes' => (int) $startUtc->diffInMinutes($endUtc),
            'status' => TimesheetStatus::Approved,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => $reviewedAt,
            'submitted_at' => $endUtc,
        ]);

        // Change start minute from 08:07 to 08:08 (substantive time change)
        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:08',
            'end_time' => '16:53',
            'admin_notes' => 'Adjusted start minute to 08:08',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
        $this->assertNull($entry->reviewed_by);
        $this->assertNull($entry->reviewed_at);

        $tz = BranchTime::timezoneFor($this->branch);
        $this->assertEquals('08:08', $entry->starts_at->timezone($tz)->format('H:i'));
        $this->assertEquals('16:53', $entry->ends_at->timezone($tz)->format('H:i'));

        $log = TimesheetAuditLog::where('timesheet_entry_id', $entry->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin_time_adjusted', $log->action);
    }

    /** 12. FormRequest validation rejects invalid times such as 25:00 and 12:60 for both employee store and admin update */
    public function test_form_request_validation_rejects_invalid_times_for_both_employee_store_and_admin_update(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        // A. Employee Store: 25:00
        $responseEmp1 = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '25:00',
            'end_time' => '17:00',
        ]);
        $responseEmp1->assertSessionHasErrors('start_time');

        // B. Employee Store: 12:60
        $responseEmp2 = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '12:60',
        ]);
        $responseEmp2->assertSessionHasErrors('end_time');

        // Existing entry for Admin Update tests
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->branch)->create([
            'work_date' => '2026-11-01',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '09:00', $this->branch)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '17:00', $this->branch)),
            'total_minutes' => 480,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // C. Admin Update: 25:00
        $responseAdmin1 = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '25:00',
            'end_time' => '17:00',
        ]);
        $responseAdmin1->assertSessionHasErrors('start_time');

        // D. Admin Update: 12:60
        $responseAdmin2 = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '12:60',
        ]);
        $responseAdmin2->assertSessionHasErrors('end_time');
    }

    /** 13. Employee manual rejected timesheet resubmission with arbitrary valid minutes works and returns entry to PendingApproval */
    public function test_employee_manual_rejected_timesheet_resubmission_with_arbitrary_valid_minutes_works(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        $rejectedEntry = TimesheetEntry::factory()->for($this->employee)->for($this->branch)->create([
            'work_date' => '2026-11-01',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '09:00', $this->branch)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-01', '17:00', $this->branch)),
            'total_minutes' => 480,
            'status' => TimesheetStatus::Rejected,
            'rejection_reason' => 'Incorrect end time recorded',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ]);

        // Employee resubmits with arbitrary valid minutes 08:07 to 16:53
        $response = $this->actingAs($this->employee)->put(route('employee.timesheets.update', $rejectedEntry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '16:53',
            'employee_notes' => 'Corrected with exact punch minutes',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $response->assertSessionHas('status', 'Timesheet resubmitted for approval.');

        $rejectedEntry->refresh();
        $this->assertEquals(TimesheetStatus::PendingApproval, $rejectedEntry->status);
        $this->assertNull($rejectedEntry->rejection_reason);
        $this->assertEquals(526, $rejectedEntry->total_minutes); // 8h 46m

        $tz = BranchTime::timezoneFor($this->branch);
        $this->assertEquals('08:07', $rejectedEntry->starts_at->timezone($tz)->format('H:i'));
        $this->assertEquals('16:53', $rejectedEntry->ends_at->timezone($tz)->format('H:i'));
        $this->assertEquals('Corrected with exact punch minutes', $rejectedEntry->employee_notes);
    }

    /** 14. Live attendance punch continues storing exact seconds */
    public function test_live_attendance_punch_continues_storing_exact_seconds(): void
    {
        // 1. Clock in at 08:07:23
        Carbon::setTestNow('2026-11-02 08:07:23');

        $responseIn = $this->actingAs($this->employee)->post(route('employee.attendance.clock-in'), [
            'branch_id' => $this->branch->id,
            'notes' => 'Morning clock in',
        ]);

        $responseIn->assertRedirect(route('employee.dashboard'));

        $entry = TimesheetEntry::where('user_id', $this->employee->id)->whereNull('ends_at')->first();
        $this->assertNotNull($entry);
        $this->assertEquals(TimesheetStatus::ClockedIn, $entry->status);
        $this->assertEquals('23', $entry->starts_at->format('s'));
        $this->assertEquals('2026-11-02 08:07:23', $entry->starts_at->toDateTimeString());

        // 2. Clock out at 16:53:41
        Carbon::setTestNow('2026-11-02 16:53:41');

        $responseOut = $this->actingAs($this->employee)->post(route('employee.attendance.clock-out'), [
            'notes' => 'Evening clock out',
        ]);

        $responseOut->assertRedirect(route('employee.dashboard'));

        $entry->refresh();
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
        $this->assertNotNull($entry->ends_at);
        $this->assertEquals('41', $entry->ends_at->format('s'));
        $this->assertEquals('2026-11-02 16:53:41', $entry->ends_at->toDateTimeString());
    }

    /** 15. A: Metadata-only update on entry adjacent by sub-minute seconds does not falsely trigger overlap validation */
    public function test_metadata_only_update_with_sub_minute_adjacent_entries_does_not_falsely_trigger_overlap_validation(): void
    {
        // Entry 1 ends at 08:07:15 local (15:07:15 UTC)
        $entry1 = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 13:00:00'), // Local 06:00:00
            'ends_at' => Carbon::parse('2026-11-01 15:07:15'),   // Local 08:07:15
            'total_minutes' => 127,
            'status' => TimesheetStatus::Approved,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => Carbon::parse('2026-11-01 23:55:00'),
            'submitted_at' => Carbon::parse('2026-11-01 15:07:15'),
        ]);

        // Entry 2 starts at 08:07:23 local (15:07:23 UTC) - non-overlapping since 15:07:15 <= 15:07:23
        $entry2 = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 15:07:23'), // Local 08:07:23
            'ends_at' => Carbon::parse('2026-11-01 23:53:41'),   // Local 16:53:41
            'total_minutes' => 526,
            'status' => TimesheetStatus::Approved,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => Carbon::parse('2026-11-01 23:55:00'),
            'submitted_at' => Carbon::parse('2026-11-01 23:53:41'),
            'employee_notes' => 'Original employee note',
        ]);

        // Admin form submits 08:07 and 16:53 without seconds, modifying only notes
        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry2), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:07',
            'end_time' => '16:53',
            'admin_notes' => 'Notes update only',
            'employee_notes' => 'Updated employee note by manager',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', 'Timesheet updated.');

        $entry2->refresh();

        // Metadata-only preserved:
        $this->assertEquals(TimesheetStatus::Approved, $entry2->status);
        $this->assertEquals($this->admin->id, $entry2->reviewed_by);
        $this->assertEquals('2026-11-01 23:55:00', $entry2->reviewed_at?->toDateTimeString());
        $this->assertEquals('2026-11-01 15:07:23', $entry2->starts_at->toDateTimeString());
        $this->assertEquals('2026-11-01 23:53:41', $entry2->ends_at->toDateTimeString());
        $this->assertEquals('23', $entry2->starts_at->format('s'));
        $this->assertEquals('41', $entry2->ends_at->format('s'));
        $this->assertEquals(526, $entry2->total_minutes);
        $this->assertEquals('Updated employee note by manager', $entry2->employee_notes);

        $log = TimesheetAuditLog::where('timesheet_entry_id', $entry2->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin_metadata_updated', $log->action);
    }

    /** 16. B: Substantive time change that genuinely overlaps another entry is rejected */
    public function test_substantive_time_change_which_genuinely_overlaps_another_entry_is_rejected(): void
    {
        // Entry 1 ends at 08:07:15 local (15:07:15 UTC)
        $entry1 = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 13:00:00'), // Local 06:00:00
            'ends_at' => Carbon::parse('2026-11-01 15:07:15'),   // Local 08:07:15
            'total_minutes' => 127,
            'status' => TimesheetStatus::Approved,
            'submitted_at' => Carbon::parse('2026-11-01 15:07:15'),
        ]);

        // Entry 2 starts at 10:00:00 local (17:00:00 UTC)
        $entry2 = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 17:00:00'), // Local 10:00:00
            'ends_at' => Carbon::parse('2026-11-01 23:00:00'),   // Local 16:00:00
            'total_minutes' => 360,
            'status' => TimesheetStatus::Approved,
            'submitted_at' => Carbon::parse('2026-11-01 23:00:00'),
        ]);

        // Substantive change: move start_time of entry 2 to 08:00 (15:00:00 UTC), which overlaps entry 1 (ends 15:07:15 UTC)
        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry2), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'admin_notes' => 'Move start earlier to 08:00',
        ]);

        $response->assertSessionHasErrors('start_time');

        // Entry 2 remains untouched
        $entry2->refresh();
        $this->assertEquals('2026-11-01 17:00:00', $entry2->starts_at->toDateTimeString());
        $this->assertEquals(TimesheetStatus::Approved, $entry2->status);
    }

    /** 17. C: Non-overlapping substantive time change still succeeds */
    public function test_substantive_time_change_without_overlap_succeeds(): void
    {
        // Entry 1 ends at 08:07:15 local (15:07:15 UTC)
        $entry1 = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 13:00:00'), // Local 06:00:00
            'ends_at' => Carbon::parse('2026-11-01 15:07:15'),   // Local 08:07:15
            'total_minutes' => 127,
            'status' => TimesheetStatus::Approved,
            'submitted_at' => Carbon::parse('2026-11-01 15:07:15'),
        ]);

        // Entry 2 starts at 11:00:00 local (18:00:00 UTC)
        $entry2 = TimesheetEntry::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 18:00:00'), // Local 11:00:00
            'ends_at' => Carbon::parse('2026-11-01 23:00:00'),   // Local 16:00:00
            'total_minutes' => 300,
            'status' => TimesheetStatus::Approved,
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => Carbon::parse('2026-11-01 23:55:00'),
            'submitted_at' => Carbon::parse('2026-11-01 23:00:00'),
        ]);

        // Substantive change: move start_time of entry 2 to 09:00 (16:00:00 UTC), which does NOT overlap entry 1 (ends 15:07:15 UTC)
        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry2), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '16:00',
            'admin_notes' => 'Adjusted start time to 09:00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', 'Timesheet updated.');

        $entry2->refresh();
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry2->status);
        $this->assertNull($entry2->reviewed_by);
        $this->assertNull($entry2->reviewed_at);

        $tz = BranchTime::timezoneFor($this->branch);
        $this->assertEquals('09:00', $entry2->starts_at->timezone($tz)->format('H:i'));
        $this->assertEquals('16:00', $entry2->ends_at->timezone($tz)->format('H:i'));
        $this->assertEquals(420, $entry2->total_minutes); // 7h = 420m

        $log = TimesheetAuditLog::where('timesheet_entry_id', $entry2->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals('admin_time_adjusted', $log->action);
    }
}

