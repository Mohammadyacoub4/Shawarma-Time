<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShawarmaApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $richmond;

    protected Branch $vancouver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BranchSeeder::class);
        $this->richmond = Branch::where('slug', 'richmond')->firstOrFail();
        $this->vancouver = Branch::where('slug', 'vancouver')->firstOrFail();
    }

    public function test_branches_are_seeded_once(): void
    {
        $this->seed(BranchSeeder::class);
        $this->assertSame(2, Branch::count());
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_employee_cannot_access_admin_dashboard(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $this->actingAs($employee)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_inactive_employee_cannot_login(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id], inactive: true);

        $this->post(route('login'), [
            'email' => $employee->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
    }

    public function test_employee_cannot_view_another_employees_timesheet(): void
    {
        $employeeA = $this->makeEmployee([$this->richmond->id]);
        $employeeB = $this->makeEmployee([$this->richmond->id]);
        $entry = TimesheetEntry::factory()->for($employeeB)->for($this->richmond)->create();

        $this->actingAs($employeeA)->get(route('employee.timesheets.edit', $entry))->assertForbidden();
    }

    public function test_unpublished_schedule_hidden_from_employees(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $week = ScheduleWeek::factory()->for($this->richmond)->create(['is_published' => false]);
        Shift::factory()->for($this->richmond)->for($employee)->for($week)->create();

        // Unpublished shifts should not appear; each day should show "No published shifts"
        $this->actingAs($employee)
            ->get(route('employee.schedules.index', ['branch_id' => $this->richmond->id]))
            ->assertOk()
            ->assertSee('No published shifts');
    }

    public function test_published_schedule_visible_to_employees(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $week = ScheduleWeek::factory()->for($this->richmond)->published()->create();
        Shift::factory()->for($this->richmond)->for($employee)->for($week)->create();

        // The employee's own shift is labelled 'You' in the published view
        $this->actingAs($employee)
            ->get(route('employee.schedules.index', ['branch_id' => $this->richmond->id]))
            ->assertOk()
            ->assertSee('You');
    }

    public function test_overlapping_shifts_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->richmond->id]);
        $week = ScheduleWeek::factory()->for($this->richmond)->create();
        Shift::factory()->for($this->richmond)->for($employee)->for($week)->create([
            'work_date' => now(BranchTime::DEFAULT_TIMEZONE)->toDateString(),
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime(now(BranchTime::DEFAULT_TIMEZONE)->toDateString(), '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime(now(BranchTime::DEFAULT_TIMEZONE)->toDateString(), '13:00')),
        ]);

        $this->actingAs($admin)->post(route('admin.schedules.shifts.store'), [
            'branch_id' => $this->richmond->id,
            'user_id' => $employee->id,
            'work_date' => now(BranchTime::DEFAULT_TIMEZONE)->toDateString(),
            'start_time' => '12:00',
            'end_time' => '16:00',
        ])->assertSessionHasErrors('shift');
    }

    public function test_timesheet_submission_and_pending_lock(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);

        $this->actingAs($employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->richmond->id,
            'work_date' => now(BranchTime::DEFAULT_TIMEZONE)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->assertRedirect(route('employee.dashboard'));

        $entry = TimesheetEntry::first();
        $this->assertSame(TimesheetStatus::PendingApproval, $entry->status);

        $this->actingAs($employee)->get(route('employee.timesheets.edit', $entry))->assertForbidden();
    }

    public function test_rejection_requires_reason_and_allows_resubmit(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->richmond->id]);
        $entry = TimesheetEntry::factory()->for($employee)->for($this->richmond)->create();

        $this->actingAs($admin)->post(route('admin.timesheets.reject', $entry), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->actingAs($admin)->post(route('admin.timesheets.reject', $entry), [
            'rejection_reason' => 'Incorrect end time',
        ])->assertRedirect();

        $entry->refresh();
        $this->assertSame(TimesheetStatus::Rejected, $entry->status);

        $this->actingAs($employee)->put(route('employee.timesheets.update', $entry), [
            'branch_id' => $this->richmond->id,
            'work_date' => $entry->work_date->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ])->assertRedirect(route('employee.dashboard'));

        $this->assertSame(TimesheetStatus::PendingApproval, $entry->fresh()->status);
        $this->assertTrue(TimesheetAuditLog::where('timesheet_entry_id', $entry->id)->exists());
    }

    public function test_admin_edit_of_approved_entry_returns_to_pending(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->richmond->id]);
        $entry = TimesheetEntry::factory()->for($employee)->for($this->richmond)->approved()->create();

        $this->actingAs($admin)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->richmond->id,
            'work_date' => $entry->work_date->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
        ])->assertRedirect();

        $this->assertSame(TimesheetStatus::PendingApproval, $entry->fresh()->status);
    }

    public function test_employee_cannot_export_reports(): void
    {
        $employee = $this->makeEmployee([$this->richmond->id]);
        $this->actingAs($employee)->get(route('admin.reports.export'))->assertForbidden();
    }

    public function test_week_start_is_monday_in_vancouver(): void
    {
        $wednesday = BranchTime::parseLocalDate('2026-03-18');
        $weekStart = BranchTime::weekStart($wednesday);
        $this->assertSame('2026-03-16', $weekStart->toDateString());
        $this->assertSame($this->carbonMonday(), $weekStart->dayOfWeek);
    }

    public function test_branch_default_timezone_is_canada_pacific(): void
    {
        $this->assertSame('Canada/Pacific', BranchTime::DEFAULT_TIMEZONE);
        $this->assertSame('Canada/Pacific', $this->richmond->timezone);
        $this->assertSame('Canada/Pacific', $this->vancouver->timezone);
    }

    public function test_admin_can_view_employee_reset_password_page(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->richmond->id]);

        $response = $this->actingAs($admin)->get(route('admin.employees.reset-password', $employee));

        $response->assertOk();
        $response->assertSee($employee->name);
        $response->assertSee('name="password"', false);
    }

    public function test_admin_can_set_custom_password_for_employee(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = $this->makeEmployee([$this->richmond->id]);

        $response = $this->actingAs($admin)->post(route('admin.employees.reset-password.update', $employee), [
            'password' => 'NewCustomPass123!',
            'password_confirmation' => 'NewCustomPass123!',
            'must_change_password' => '1',
        ]);

        $response->assertRedirect(route('admin.employees.show', $employee));
        $employee->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewCustomPass123!', $employee->password));
        $this->assertTrue((bool) $employee->must_change_password);
    }

    public function test_employee_cannot_access_employee_reset_password_page(): void
    {
        $employee1 = $this->makeEmployee([$this->richmond->id]);
        $employee2 = $this->makeEmployee([$this->vancouver->id]);

        $response = $this->actingAs($employee1)->get(route('admin.employees.reset-password', $employee2));

        $response->assertForbidden();
    }

    public function test_admin_can_filter_employees_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $activeEmp = $this->makeEmployee([$this->richmond->id], inactive: false);
        $activeEmp->update(['name' => 'Active Worker']);
        $inactiveEmp = $this->makeEmployee([$this->richmond->id], inactive: true);
        $inactiveEmp->update(['name' => 'Inactive Worker']);

        // Filter active
        $responseActive = $this->actingAs($admin)->get(route('admin.employees.index', ['status' => 'active']));
        $responseActive->assertOk();
        $responseActive->assertSee('Active Worker');
        $responseActive->assertDontSee('Inactive Worker');

        // Filter inactive
        $responseInactive = $this->actingAs($admin)->get(route('admin.employees.index', ['status' => 'inactive']));
        $responseInactive->assertOk();
        $responseInactive->assertSee('Inactive Worker');
        $responseInactive->assertDontSee('Active Worker');
    }

    public function test_admin_can_filter_employees_by_branch(): void
    {
        $admin = User::factory()->admin()->create();
        $richmondEmp = $this->makeEmployee([$this->richmond->id]);
        $richmondEmp->update(['name' => 'Richmond Staff']);
        $vancouverEmp = $this->makeEmployee([$this->vancouver->id]);
        $vancouverEmp->update(['name' => 'Vancouver Staff']);

        $response = $this->actingAs($admin)->get(route('admin.employees.index', ['branch_id' => $this->richmond->id]));
        $response->assertOk();
        $response->assertSee('Richmond Staff');
        $response->assertDontSee('Vancouver Staff');
    }

    public function test_admin_can_search_employees_by_name_phone_or_email(): void
    {
        $admin = User::factory()->admin()->create();
        $emp = $this->makeEmployee([$this->richmond->id]);
        $emp->update([
            'name' => 'Unique Employee',
            'phone' => '6045551234',
            'email' => 'unique.staff@shawarmatime.ca',
        ]);

        // Search by phone
        $resPhone = $this->actingAs($admin)->get(route('admin.employees.index', ['q' => '6045551234']));
        $resPhone->assertOk();
        $resPhone->assertSee('Unique Employee');

        // Search by email
        $resEmail = $this->actingAs($admin)->get(route('admin.employees.index', ['q' => 'unique.staff']));
        $resEmail->assertOk();
        $resEmail->assertSee('Unique Employee');
    }

    private function carbonMonday(): int
    {
        return \Carbon\Carbon::MONDAY;
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function makeEmployee(array $branchIds, bool $inactive = false): User
    {
        $employee = User::factory()->employee()->when($inactive, fn ($f) => $f->inactive())->create();
        $employee->branches()->sync($branchIds);

        return $employee;
    }
}
