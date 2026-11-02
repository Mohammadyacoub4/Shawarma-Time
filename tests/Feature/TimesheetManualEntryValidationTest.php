<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use App\Support\BranchTime;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class TimesheetManualEntryValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee;
    private User $otherEmployee;
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

        $this->otherEmployee = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->otherEmployee->branches()->sync([$this->vancouver->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 1. Future work_date is rejected */
    public function test_employee_submission_with_future_work_date_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00'); // Vancouver local: 2026-11-02 04:00:00

        $tomorrow = '2026-11-03';

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'work_date' => $tomorrow,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('work_date');
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 2. Yesterday's work_date is accepted */
    public function test_employee_submission_with_yesterday_work_date_is_accepted(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00'); // Vancouver local: 2026-11-02 10:00:00

        $yesterday = '2026-11-01';

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'work_date' => $yesterday,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals($this->vancouver->id, $entry->branch_id);
        $this->assertEquals($yesterday, $entry->work_date->toDateString());
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
    }

    /** 3. Today's work_date is accepted */
    public function test_employee_submission_with_today_work_date_is_accepted(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00'); // Vancouver local: 2026-11-02 12:00:00

        $today = '2026-11-02';

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'work_date' => $today,
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals($this->vancouver->id, $entry->branch_id);
        $this->assertEquals($today, $entry->work_date->toDateString());
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
    }

    /** 4. Future date is rejected for an unscheduled entry too */
    public function test_employee_unscheduled_submission_with_future_date_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');

        $futureDate = '2026-11-10';

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => null,
            'work_date' => $futureDate,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('work_date');
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 5. A shift with a mismatched work_date is rejected */
    public function test_employee_submission_with_mismatched_shift_and_work_date_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');

        $week = ScheduleWeek::factory()->for($this->vancouver)->create(['is_published' => true]);
        $shift = Shift::factory()->for($this->vancouver)->for($this->employee)->for($week)->create([
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00', $this->vancouver)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00', $this->vancouver)),
        ]);

        // Submit referencing shift for 2026-11-02, but giving work_date 2026-11-01
        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('work_date');
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 6. A shift belonging to another employee is rejected */
    public function test_employee_submission_with_another_employees_shift_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');

        $week = ScheduleWeek::factory()->for($this->vancouver)->create(['is_published' => true]);
        $otherShift = Shift::factory()->for($this->vancouver)->for($this->otherEmployee)->for($week)->create([
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00', $this->vancouver)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00', $this->vancouver)),
        ]);

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $otherShift->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertForbidden();
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 7. A shift belonging to another branch is rejected */
    public function test_employee_submission_with_shift_from_another_branch_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');

        $week = ScheduleWeek::factory()->for($this->richmond)->create(['is_published' => true]);
        $richmondShift = Shift::factory()->for($this->richmond)->for($this->employee)->for($week)->create([
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00', $this->richmond)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00', $this->richmond)),
        ]);

        // Submit to Vancouver with Richmond's shift_id
        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $richmondShift->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertForbidden();
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 8. An unpublished shift is rejected */
    public function test_employee_submission_with_unpublished_shift_is_rejected(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');

        $draftWeek = ScheduleWeek::factory()->for($this->vancouver)->create(['is_published' => false]);
        $draftShift = Shift::factory()->for($this->vancouver)->for($this->employee)->for($draftWeek)->create([
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00', $this->vancouver)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00', $this->vancouver)),
        ]);

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $draftShift->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('shift_id');
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 9. A valid published shift with matching work_date is accepted */
    public function test_employee_submission_with_valid_published_shift_and_matching_work_date_is_accepted(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        $publishedWeek = ScheduleWeek::factory()->for($this->vancouver)->create(['is_published' => true]);
        $shift = Shift::factory()->for($this->vancouver)->for($this->employee)->for($publishedWeek)->create([
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00', $this->vancouver)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00', $this->vancouver)),
        ]);

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals($shift->id, $entry->shift_id);
        $this->assertEquals('2026-11-02', $entry->work_date->toDateString());
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
    }

    /** 10. A valid unscheduled submission with null shift_id remains accepted */
    public function test_employee_valid_unscheduled_submission_with_null_shift_id_is_accepted(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        $response = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => '',
            'work_date' => '2026-11-02',
            'start_time' => '10:00',
            'end_time' => '14:00',
            'employee_notes' => 'Emergency cover',
        ]);

        $response->assertRedirect(route('employee.dashboard'));
        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->shift_id);
        $this->assertEquals('2026-11-02', $entry->work_date->toDateString());
        $this->assertEquals('Emergency cover', $entry->employee_notes);
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
    }

    /** 11 & 12. Timezone boundary test: uses branch local date, not UTC date */
    public function test_future_date_validation_uses_branch_local_date_across_utc_boundary(): void
    {
        // 2026-11-02 03:00:00 UTC
        // Vancouver (Canada/Pacific = UTC-8): 2026-11-01 19:00:00 (Nov 1)
        // UTC date is 2026-11-02, but Vancouver local date is still 2026-11-01
        Carbon::setTestNow('2026-11-02 03:00:00');

        // Submitting 2026-11-02 for Vancouver is FUTURE because local date is 2026-11-01
        $responseFuture = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
        $responseFuture->assertSessionHasErrors('work_date');

        // Submitting 2026-11-01 for Vancouver is TODAY locally, so it must be ACCEPTED
        $responseToday = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-01',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
        $responseToday->assertRedirect(route('employee.dashboard'));
        $entry = TimesheetEntry::where('user_id', $this->employee->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals('2026-11-01', $entry->work_date->toDateString());
    }

    /** 13. Admin cannot associate a timesheet with another employee's shift */
    public function test_admin_cannot_associate_timesheet_with_another_employees_shift(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-15',
        ]);

        $otherShift = Shift::factory()->for($this->vancouver)->for($this->otherEmployee)->create([
            'work_date' => '2026-10-15',
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $otherShift->id,
            'work_date' => '2026-10-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('shift_id');
        $this->assertNull($entry->fresh()->shift_id);
    }

    /** 14. Admin cannot associate a timesheet with a shift from another branch */
    public function test_admin_cannot_associate_timesheet_with_shift_from_another_branch(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-15',
        ]);

        $richmondShift = Shift::factory()->for($this->richmond)->for($this->employee)->create([
            'work_date' => '2026-10-15',
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $richmondShift->id,
            'work_date' => '2026-10-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('shift_id');
        $this->assertNull($entry->fresh()->shift_id);
    }

    /** 15. Admin cannot associate a shift whose work_date differs from the updated work_date */
    public function test_admin_cannot_associate_shift_with_mismatched_work_date(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-15',
        ]);

        $shift = Shift::factory()->for($this->vancouver)->for($this->employee)->create([
            'work_date' => '2026-10-20',
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-10-15', // Does not match shift date 2026-10-20
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('work_date');
        $this->assertNull($entry->fresh()->shift_id);
    }

    /** 16. Existing valid historical admin corrections remain possible */
    public function test_existing_valid_historical_admin_corrections_remain_possible(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->vancouver)->approved()->create([
            'work_date' => '2026-05-10',
            'total_minutes' => 480,
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'admin_notes' => 'Adjusted punch time as requested',
        ]);

        $response->assertRedirect();
        $entry->refresh();
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
        $this->assertEquals('2026-05-10', $entry->work_date->toDateString());
    }

    /** 17. Existing allowed null shift_id behavior remains intact */
    public function test_admin_update_with_null_shift_id_is_permitted(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-15',
            'shift_id' => null,
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->vancouver->id,
            'shift_id' => null,
            'work_date' => '2026-10-15',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertRedirect();
        $this->assertNull($entry->fresh()->shift_id);
    }

    /** 18. Service-level domain enforcement and rejection guarantees no records created or altered */
    public function test_service_level_enforcement_rejects_invariants_without_corrupting_state(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');
        $service = app(TimesheetService::class);

        // A. Service rejects future date
        try {
            $service->submit($this->employee, [
                'branch_id' => $this->vancouver->id,
                'work_date' => '2026-11-05',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
            $this->fail('Expected ValidationException for future work date.');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['work_date']));
        }
        $this->assertEquals(0, TimesheetEntry::count());

        // B. Service rejects unassigned branch
        $unassignedBranch = Branch::factory()->create();
        try {
            $service->submit($this->employee, [
                'branch_id' => $unassignedBranch->id,
                'work_date' => '2026-11-01',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
            $this->fail('Expected InvalidArgumentException for unassigned branch.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Employee is not assigned to this branch', $e->getMessage());
        }
        $this->assertEquals(0, TimesheetEntry::count());

        // C. Service rejects unpublished shift
        $draftWeek = ScheduleWeek::factory()->for($this->vancouver)->create([
            'week_start' => '2026-10-26 00:00:00',
            'is_published' => false,
        ]);
        $draftShift = Shift::factory()->for($this->vancouver)->for($this->employee)->for($draftWeek)->create([
            'work_date' => '2026-10-27',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-10-27', '09:00', $this->vancouver)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-10-27', '17:00', $this->vancouver)),
        ]);
        try {
            $service->submit($this->employee, [
                'branch_id' => $this->vancouver->id,
                'shift_id' => $draftShift->id,
                'work_date' => '2026-10-27',
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
            $this->fail('Expected ValidationException for unpublished shift.');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['shift_id']));
        }
        $this->assertEquals(0, TimesheetEntry::count());

        // D. Service rejects mismatched shift work_date
        $pubWeek = ScheduleWeek::factory()->for($this->vancouver)->create([
            'week_start' => '2026-11-02 00:00:00',
            'is_published' => true,
        ]);
        $shift = Shift::factory()->for($this->vancouver)->for($this->employee)->for($pubWeek)->create([
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00', $this->vancouver)),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00', $this->vancouver)),
        ]);
        try {
            $service->submit($this->employee, [
                'branch_id' => $this->vancouver->id,
                'shift_id' => $shift->id,
                'work_date' => '2026-11-01', // Mismatched date
                'start_time' => '09:00',
                'end_time' => '17:00',
            ]);
            $this->fail('Expected ValidationException for mismatched work date.');
        } catch (ValidationException $e) {
            $this->assertTrue(isset($e->errors()['work_date']));
        }
        $this->assertEquals(0, TimesheetEntry::count());
    }

    /** 19. Resubmission of rejected timesheet adheres to same invariants */
    public function test_resubmission_of_rejected_timesheet_enforces_invariants(): void
    {
        Carbon::setTestNow('2026-11-02 18:00:00');

        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-01',
            'status' => TimesheetStatus::Rejected,
            'rejection_reason' => 'Invalid end time',
        ]);

        // Resubmit with future date must fail
        $response = $this->actingAs($this->employee)->put(route('employee.timesheets.update', $entry), [
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-05',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $response->assertSessionHasErrors('work_date');
        $entry->refresh();
        $this->assertEquals(TimesheetStatus::Rejected, $entry->status);
        $this->assertEquals('2026-11-01', $entry->work_date->toDateString());
    }
}
