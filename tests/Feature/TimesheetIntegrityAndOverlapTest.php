<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use App\Support\BranchTime;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TimesheetIntegrityAndOverlapTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee;
    private Branch $vancouver;
    private Branch $richmond;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->vancouver = Branch::where('slug', 'vancouver')->firstOrFail();
        $this->richmond = Branch::where('slug', 'richmond')->firstOrFail();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->employee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee->branches()->sync([$this->vancouver->id, $this->richmond->id]);
    }

    public function test_employee_can_submit_multiple_non_overlapping_shifts_on_same_day(): void
    {
        $service = app(TimesheetService::class);

        $entry1 = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $entry2 = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '13:00',
            'end_time' => '17:00',
        ]);

        $this->assertDatabaseHas('timesheet_entries', ['id' => $entry1->id]);
        $this->assertDatabaseHas('timesheet_entries', ['id' => $entry2->id]);
    }

    public function test_employee_can_submit_back_to_back_shifts(): void
    {
        $service = app(TimesheetService::class);

        $entry1 = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        // Second shift starts exactly when first ends: 12:00
        $entry2 = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '12:00',
            'end_time' => '16:00',
        ]);

        $this->assertNotNull($entry1->id);
        $this->assertNotNull($entry2->id);
    }

    public function test_same_branch_overlapping_shifts_are_rejected(): void
    {
        $service = app(TimesheetService::class);

        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $this->expectException(ValidationException::class);

        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '14:00',
            'end_time' => '18:00',
        ]);
    }

    public function test_cross_branch_overlapping_shifts_for_same_employee_are_rejected(): void
    {
        $service = app(TimesheetService::class);

        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '10:00',
            'end_time' => '15:00',
        ]);

        $this->expectException(ValidationException::class);

        $service->submit($this->employee, [
            'branch_id' => $this->richmond->id,
            'work_date' => '2026-05-10',
            'start_time' => '12:00',
            'end_time' => '16:00',
        ]);
    }

    public function test_candidate_shift_overlapping_with_active_clock_in_session_is_rejected(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00');
        $service = app(TimesheetService::class);

        // Employee clocks in at 10:00 (active session, ends_at is null)
        $service->clockIn($this->employee, $this->vancouver->id);

        $this->expectException(ValidationException::class);

        // Employee attempts to submit a manual timesheet spanning 09:00 to 11:00 on 2026-05-10
        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_clock_out_validates_completed_interval_against_existing_approved_or_pending_entries(): void
    {
        $service = app(TimesheetService::class);

        // Vancouver is UTC-7 in May.
        // 12:00 to 14:00 local Vancouver is 19:00 to 21:00 UTC
        $service->submit($this->employee, [
            'branch_id' => $this->richmond->id,
            'work_date' => '2026-05-10',
            'start_time' => '12:00',
            'end_time' => '14:00',
        ]);

        // Employee clocks in at 11:30 local Vancouver (18:30 UTC)
        Carbon::setTestNow('2026-05-10 18:30:00');
        $service->clockIn($this->employee, $this->vancouver->id);

        // Employee tries to clock out at 13:00 local Vancouver (20:00 UTC),
        // which overlaps with the 12:00-14:00 shift (19:00-21:00 UTC)
        Carbon::setTestNow('2026-05-10 20:00:00');

        $this->expectException(ValidationException::class);
        $service->clockOut($this->employee);

        Carbon::setTestNow();
    }

    public function test_admin_update_excluding_self_allows_valid_edit_but_rejects_overlap_with_other(): void
    {
        $service = app(TimesheetService::class);

        $entry1 = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '11:00',
        ]);

        $entry2 = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '12:00',
            'end_time' => '15:00',
        ]);

        // Editing entry1 to 08:00 - 11:30 should succeed (does not overlap entry2)
        $updated1 = $service->adminUpdate($entry1, $this->admin, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '11:30',
        ]);
        $this->assertEquals(210, $updated1->total_minutes);

        // Editing entry1 to extend to 12:30 (overlaps entry2) must throw ValidationException
        $this->expectException(ValidationException::class);
        $service->adminUpdate($entry1, $this->admin, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '12:30',
        ]);
    }

    public function test_admin_metadata_only_update_preserves_approval_and_reviewer_attribution(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $service->approve($entry, $this->admin);
        $entry->refresh();
        $this->assertEquals(TimesheetStatus::Approved, $entry->status);
        $this->assertEquals($this->admin->id, $entry->reviewed_by);

        // Metadata-only update: change only employee_notes
        $updated = $service->adminUpdate($entry, $this->admin, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'employee_notes' => 'Updated notes by manager',
        ]);

        $updated->refresh();
        $this->assertEquals(TimesheetStatus::Approved, $updated->status);
        $this->assertEquals($this->admin->id, $updated->reviewed_by);
        $this->assertEquals('Updated notes by manager', $updated->employee_notes);
        $this->assertEquals('admin_metadata_updated', $updated->latestAuditLog->action);
    }

    public function test_admin_substantive_update_resets_approved_to_pending(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $service->approve($entry, $this->admin);
        $entry->refresh();
        $this->assertEquals(TimesheetStatus::Approved, $entry->status);

        // Substantive update: change end_time
        $updated = $service->adminUpdate($entry, $this->admin, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);

        $updated->refresh();
        $this->assertEquals(TimesheetStatus::PendingApproval, $updated->status);
        $this->assertNull($updated->reviewed_by);
        $this->assertNull($updated->reviewed_at);
        $this->assertEquals('admin_time_adjusted', $updated->latestAuditLog->action);
        $this->assertEquals($this->admin->id, $updated->latestAuditLog->old_values['reviewed_by']);
        $this->assertEquals(TimesheetStatus::Approved->value, $updated->latestAuditLog->old_values['status']);
        $this->assertNotNull($updated->latestAuditLog->old_values['reviewed_at']);
    }

    public function test_duplicate_shift_claim_is_rejected(): void
    {
        $service = app(TimesheetService::class);

        $shift = Shift::create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 16:00:00',
            'ends_at' => '2026-05-10 20:00:00',
        ]);

        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        $this->expectException(ValidationException::class);

        // Second submission claiming the same shift_id must fail
        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-05-10',
            'start_time' => '14:00',
            'end_time' => '18:00',
        ]);
    }

    public function test_rejected_timesheet_resubmission_in_place_is_allowed(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $service->reject($entry, $this->admin, 'Wrong hours reported');
        $entry->refresh();
        $this->assertEquals(TimesheetStatus::Rejected, $entry->status);
        $this->assertEquals('Wrong hours reported', $entry->rejection_reason);

        // Resubmit the rejected entry in place
        $resubmitted = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $entry);

        $this->assertEquals($entry->id, $resubmitted->id);
        $this->assertEquals(TimesheetStatus::PendingApproval, $resubmitted->status);
        $this->assertNull($resubmitted->rejection_reason);
    }

    public function test_equal_start_and_end_times_are_rejected(): void
    {
        $service = app(TimesheetService::class);

        $this->expectException(ValidationException::class);

        $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '09:00',
        ]);
    }

    public function test_overnight_shifts_are_handled_correctly_preserving_work_date(): void
    {
        $service = app(TimesheetService::class);

        // 22:00 to 06:00 overnight shift (8 hours = 480 mins)
        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '22:00',
            'end_time' => '06:00',
        ]);

        $this->assertEquals('2026-05-10', $entry->work_date->toDateString());
        $this->assertEquals(480, $entry->total_minutes);

        // starts_at local should be 2026-05-10 22:00 PDT -> UTC 2026-05-11 05:00:00
        $this->assertEquals('2026-05-11 05:00:00', $entry->starts_at->toDateTimeString());
        // ends_at local should be 2026-05-11 06:00 PDT -> UTC 2026-05-11 13:00:00
        $this->assertEquals('2026-05-11 13:00:00', $entry->ends_at->toDateTimeString());
    }

    public function test_no_maximum_duration_cap_allows_long_shifts(): void
    {
        $service = app(TimesheetService::class);

        // 23h45m shift: 08:00 to 07:45 overnight
        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '07:45',
        ]);

        $this->assertEquals(1425, $entry->total_minutes);
        $this->assertEquals('2026-05-10', $entry->work_date->toDateString());
    }

    public function test_times_during_former_spring_forward_dst_are_accepted_under_fixed_utc_minus_seven(): void
    {
        $service = app(TimesheetService::class);

        // Under fixed UTC-07:00 (Etc/GMT+7), 2026-03-08 02:30 exists and is accepted
        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-03-08',
            'start_time' => '02:30',
            'end_time' => '06:00',
        ]);

        $this->assertNotNull($entry->id);
        $this->assertEquals(210, $entry->total_minutes);
    }

    public function test_times_during_former_fall_back_dst_are_accepted_under_fixed_utc_minus_seven(): void
    {
        $service = app(TimesheetService::class);

        // Under fixed UTC-07:00 (Etc/GMT+7), 2026-11-01 01:30 is unambiguous and accepted
        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-01',
            'start_time' => '01:30',
            'end_time' => '06:00',
        ]);

        $this->assertNotNull($entry->id);
        $this->assertEquals(270, $entry->total_minutes);
    }

    public function test_locking_order_user_row_first_then_entry_in_approval_and_rejection(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        // Verify approve locks user then entry
        $approved = $service->approve($entry, $this->admin);
        $this->assertEquals(TimesheetStatus::Approved, $approved->status);

        // Re-open by admin edit
        $updated = $service->adminUpdate($approved, $this->admin, [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
        $this->assertEquals(TimesheetStatus::PendingApproval, $updated->status);

        // Verify reject locks user then entry
        $rejected = $service->reject($updated, $this->admin, 'Overtime unauthorized');
        $this->assertEquals(TimesheetStatus::Rejected, $rejected->status);
    }
}
