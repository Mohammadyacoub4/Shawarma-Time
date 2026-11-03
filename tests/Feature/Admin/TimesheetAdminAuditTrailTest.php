<?php

namespace Tests\Feature\Admin;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetAdminAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;
    private User $adminB;
    private User $employee;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') !== 'shawarma_time_testing') {
            throw new \RuntimeException('SAFETY BLOCK: Connected to non-testing database: ' . config('database.connections.mysql.database'));
        }

        $this->seed(BranchSeeder::class);
        $this->branch = Branch::where('slug', 'vancouver')->firstOrFail();

        $this->adminA = User::factory()->create([
            'name' => 'Admin Alice',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->adminB = User::factory()->create([
            'name' => 'Admin Bob',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->employee = User::factory()->create([
            'name' => 'Employee John',
            'role' => 'employee',
            'is_active' => true,
        ]);

        $this->employee->branches()->attach($this->branch->id);
    }

    public function test_admin_notes_submitted_via_form_are_saved_in_audit_log_notes_and_employee_notes_unchanged(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'employee_notes' => 'Original employee note',
        ]);

        $response = $this->actingAs($this->adminA)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'admin_notes' => 'Reason for review: Verified with manager',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Timesheet updated.');

        $entry->refresh();
        $this->assertEquals('Original employee note', $entry->employee_notes);

        $latestLog = $entry->latestAuditLog;
        $this->assertNotNull($latestLog);
        $this->assertEquals('Reason for review: Verified with manager', $latestLog->notes);
        $this->assertEquals('admin_metadata_updated', $latestLog->action);
        $this->assertEquals($this->adminA->id, $latestLog->performed_by);
    }

    public function test_substantive_admin_edit_logs_admin_time_adjusted_and_preserves_notes(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'employee_notes' => 'Regular shift',
        ]);

        $response = $this->actingAs($this->adminA)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'admin_notes' => 'Overtime confirmed with dispatch',
        ]);

        $response->assertRedirect();

        $entry->refresh();
        $this->assertEquals('Regular shift', $entry->employee_notes);
        $this->assertEquals(540, $entry->total_minutes);

        $latestLog = $entry->latestAuditLog;
        $this->assertEquals('admin_time_adjusted', $latestLog->action);
        $this->assertEquals('Overtime confirmed with dispatch', $latestLog->notes);
        $this->assertEquals($this->adminA->id, $latestLog->performed_by);
    }

    public function test_substantive_edit_on_approved_entry_preserves_original_approval_attribution_in_old_values(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $service->approve($entry, $this->adminA);
        $entry->refresh();

        $this->assertEquals(TimesheetStatus::Approved, $entry->status);
        $this->assertEquals($this->adminA->id, $entry->reviewed_by);
        $this->assertNotNull($entry->reviewed_at);

        // Admin B performs substantive edit
        $service->adminUpdate($entry, $this->adminB, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '10:00',
            'end_time' => '18:00',
            'admin_notes' => 'Adjustment requested by client',
        ]);

        $entry->refresh();

        // Status must be reset to pending and reviewed fields nulled
        $this->assertEquals(TimesheetStatus::PendingApproval, $entry->status);
        $this->assertNull($entry->reviewed_by);
        $this->assertNull($entry->reviewed_at);

        // Audit log must preserve original approval history in old_values
        $latestLog = $entry->latestAuditLog;
        $this->assertEquals('admin_time_adjusted', $latestLog->action);
        $this->assertEquals($this->adminB->id, $latestLog->performed_by);
        $this->assertEquals($this->adminA->id, $latestLog->old_values['reviewed_by']);
        $this->assertEquals(TimesheetStatus::Approved->value, $latestLog->old_values['status']);
        $this->assertNotNull($latestLog->old_values['reviewed_at']);
        $this->assertEquals('Adjustment requested by client', $latestLog->notes);
    }

    public function test_admin_b_modifying_entry_approved_by_admin_a_identifies_both_actors(): void
    {
        $service = app(TimesheetService::class);

        $entry = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        // Admin A approves
        $service->approve($entry, $this->adminA);
        $entry->refresh();
        $this->assertEquals($this->adminA->id, $entry->reviewed_by);

        // Admin B updates metadata notes
        $service->adminUpdate($entry, $this->adminB, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'admin_notes' => 'Admin B note check',
        ]);

        $entry->refresh();

        // Reviewer attribution on entry remains Admin A (not overwritten by metadata edit)
        $this->assertEquals($this->adminA->id, $entry->reviewed_by);
        $this->assertEquals(TimesheetStatus::Approved, $entry->status);

        // Latest modifier is Admin B via latestAuditLog
        $this->assertEquals($this->adminB->id, $entry->latestAuditLog->performed_by);
        $this->assertEquals($this->adminB->name, $entry->latestAuditLog->performer->name);
        $this->assertEquals('admin_metadata_updated', $entry->latestAuditLog->action);

        // Audit logs show both events in sequence
        $logs = $entry->auditLogs()->orderBy('id')->get();
        $this->assertCount(3, $logs); // submitted, approved, admin_metadata_updated
        $this->assertEquals('submitted', $logs[0]->action);
        $this->assertEquals('approved', $logs[1]->action);
        $this->assertEquals($this->adminA->id, $logs[1]->performed_by);
        $this->assertEquals('admin_metadata_updated', $logs[2]->action);
        $this->assertEquals($this->adminB->id, $logs[2]->performed_by);
    }

    public function test_historical_admin_edited_records_remain_readable(): void
    {
        $entry = TimesheetEntry::factory()->for($this->employee)->for($this->branch)->create();

        // Simulate an older historical audit record created before the migration
        $historicalLog = TimesheetAuditLog::create([
            'timesheet_entry_id' => $entry->id,
            'performed_by' => $this->adminA->id,
            'action' => 'admin_edited',
            'old_values' => ['total_minutes' => 480],
            'new_values' => ['total_minutes' => 450],
            'notes' => 'Historical edit log note',
        ]);

        $entry->refresh();

        $this->assertEquals('admin_edited', $entry->latestAuditLog->action);
        $this->assertEquals($this->adminA->id, $entry->latestAuditLog->performed_by);
        $this->assertEquals('Historical edit log note', $entry->latestAuditLog->notes);

        // Render the view to ensure show.blade.php handles historical records without error
        $response = $this->actingAs($this->adminA)->get(route('admin.timesheets.show', $entry));
        $response->assertOk();
        $response->assertSee('admin_edited');
        $response->assertSee('Historical edit log note');
    }

    public function test_admin_opening_active_clocked_in_timesheet_with_null_ends_at_succeeds(): void
    {
        $service = app(TimesheetService::class);
        $entry = $service->clockIn($this->employee, $this->branch->id, 'Starting my morning shift');

        $this->assertNull($entry->ends_at);
        $this->assertEquals(TimesheetStatus::ClockedIn, $entry->status);

        $response = $this->actingAs($this->adminA)->get(route('admin.timesheets.show', $entry));

        $response->assertOk();
        $response->assertSee($this->employee->name);
        $response->assertSee($this->branch->name);

        $expectedStartTime = $entry->starts_at->timezone(\App\Support\BranchTime::timezoneFor($entry->branch))->format('g:i A');
        $response->assertSee($expectedStartTime);
        $response->assertSee('Not clocked out yet');
        $response->assertSee('Currently Clocked In');
        $response->assertDontSee('name="end_time"', false);
    }

    public function test_admin_viewing_completed_timesheet_shows_editing_form_and_end_time(): void
    {
        $service = app(TimesheetService::class);
        $entry = $service->submit($this->employee, [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-05-10',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $this->assertNotNull($entry->ends_at);

        $response = $this->actingAs($this->adminA)->get(route('admin.timesheets.show', $entry));

        $response->assertOk();
        $response->assertSee('name="start_time"', false);
        $response->assertSee($entry->starts_at->timezone(\App\Support\BranchTime::timezoneFor($entry->branch))->format('H:i'));
        $response->assertSee('name="end_time"', false);
        $response->assertSee($entry->ends_at->timezone(\App\Support\BranchTime::timezoneFor($entry->branch))->format('H:i'));
        $response->assertSee('Save Changes');
        $response->assertDontSee('Not clocked out yet');
    }

    public function test_admin_cannot_update_timesheet_without_end_time(): void
    {
        $service = app(TimesheetService::class);
        $entry = $service->clockIn($this->employee, $this->branch->id, 'Active shift');

        $response = $this->actingAs($this->adminA)->put(route('admin.timesheets.update', $entry), [
            'branch_id' => $this->branch->id,
            'work_date' => $entry->work_date->toDateString(),
            'start_time' => '09:00',
        ]);

        $response->assertSessionHasErrors('end_time');
        $this->assertNull($entry->fresh()->ends_at);
        $this->assertEquals(TimesheetStatus::ClockedIn, $entry->fresh()->status);
    }
}
