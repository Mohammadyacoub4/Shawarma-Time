<?php

namespace Tests\Feature\Admin;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class TimesheetApprovalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee;
    private User $peerEmployee;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'mysql' && config('database.connections.mysql.database') !== 'shawarma_time_testing') {
            throw new \RuntimeException('SAFETY BLOCK: Connected to non-testing database: ' . config('database.connections.mysql.database'));
        }

        $this->seed(BranchSeeder::class);
        $this->branch = Branch::where('slug', 'vancouver')->firstOrFail();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Admin Manager',
            'email' => 'admin.manager@shawarma.test',
        ]);

        $this->employee = User::factory()->employee()->create([
            'name' => 'Regular Employee',
            'email' => 'employee@shawarma.test',
        ]);
        $this->employee->branches()->attach($this->branch->id);

        $this->peerEmployee = User::factory()->employee()->create([
            'name' => 'Peer Employee',
            'email' => 'peer.employee@shawarma.test',
        ]);
        $this->peerEmployee->branches()->attach($this->branch->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_authenticated_employee_cannot_directly_approve_or_reject_own_timesheet(): void
    {
        $timesheet = TimesheetEntry::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // 1. Employee attempts to POST directly to admin approval endpoint for own timesheet
        $approveResponse = $this->actingAs($this->employee)
            ->post(route('admin.timesheets.approve', $timesheet));

        $approveResponse->assertForbidden();

        // 2. Employee attempts to POST directly to admin rejection endpoint for own timesheet
        $rejectResponse = $this->actingAs($this->employee)
            ->post(route('admin.timesheets.reject', $timesheet), [
                'rejection_reason' => 'Unauthorized self-rejection attempt',
            ]);

        $rejectResponse->assertForbidden();

        // 3. Assert timesheet remains PendingApproval
        $timesheet->refresh();
        $this->assertSame(TimesheetStatus::PendingApproval, $timesheet->status);
        $this->assertNull($timesheet->reviewed_by);
        $this->assertNull($timesheet->reviewed_at);
        $this->assertNull($timesheet->rejection_reason);

        // 4. Assert no approval/rejection audit event was created
        $this->assertDatabaseMissing('timesheet_audit_logs', [
            'timesheet_entry_id' => $timesheet->id,
            'action' => 'approved',
        ]);
        $this->assertDatabaseMissing('timesheet_audit_logs', [
            'timesheet_entry_id' => $timesheet->id,
            'action' => 'rejected',
        ]);
        $this->assertSame(
            0,
            TimesheetAuditLog::where('timesheet_entry_id', $timesheet->id)
                ->whereIn('action', ['approved', 'rejected'])
                ->count()
        );
    }

    public function test_authenticated_employee_cannot_directly_approve_or_reject_peer_timesheet(): void
    {
        $peerTimesheet = TimesheetEntry::factory()->create([
            'user_id' => $this->peerEmployee->id,
            'branch_id' => $this->branch->id,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // 1. Employee attempts to POST directly to admin approval endpoint for peer timesheet
        $approveResponse = $this->actingAs($this->employee)
            ->post(route('admin.timesheets.approve', $peerTimesheet));

        $approveResponse->assertForbidden();

        // 2. Employee attempts to POST directly to admin rejection endpoint for peer timesheet
        $rejectResponse = $this->actingAs($this->employee)
            ->post(route('admin.timesheets.reject', $peerTimesheet), [
                'rejection_reason' => 'Unauthorized peer rejection attempt',
            ]);

        $rejectResponse->assertForbidden();

        // 3. Assert timesheet remains PendingApproval
        $peerTimesheet->refresh();
        $this->assertSame(TimesheetStatus::PendingApproval, $peerTimesheet->status);
        $this->assertNull($peerTimesheet->reviewed_by);
        $this->assertNull($peerTimesheet->reviewed_at);

        // 4. Assert no approval/rejection audit event was created
        $this->assertSame(
            0,
            TimesheetAuditLog::where('timesheet_entry_id', $peerTimesheet->id)
                ->whereIn('action', ['approved', 'rejected'])
                ->count()
        );
    }

    public function test_admin_approval_preserves_exact_stored_total_minutes_calculated_at_submission(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00'); // Vancouver local: 2026-11-02 12:00:00

        // Employee submits a timesheet for today: 08:15 to 16:45 (8 hours and 30 minutes = 510 minutes)
        $submitResponse = $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-02',
            'start_time' => '08:15',
            'end_time' => '16:45',
            'employee_notes' => 'Full shift submitted',
        ]);

        $submitResponse->assertRedirect(route('employee.dashboard'));

        $timesheet = TimesheetEntry::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
        $this->assertSame(TimesheetStatus::PendingApproval, $timesheet->status);

        // Verify total_minutes is calculated upon submission by WorkPeriodCalculator
        $this->assertSame(510, (int) $timesheet->total_minutes);

        // Administrator approves via admin HTTP endpoint
        $approveResponse = $this->actingAs($this->admin)->post(route('admin.timesheets.approve', $timesheet));

        $approveResponse->assertRedirect();
        $approveResponse->assertSessionHas('status', 'Timesheet approved.');

        // Verify exact stored total_minutes is preserved intact and not mutated or reset upon approval
        $timesheet->refresh();
        $this->assertSame(TimesheetStatus::Approved, $timesheet->status);
        $this->assertSame($this->admin->id, $timesheet->reviewed_by);
        $this->assertNotNull($timesheet->reviewed_at);
        $this->assertSame(510, (int) $timesheet->total_minutes);

        // Verify audit log tracks approval with correct performer and status transition
        $auditLog = TimesheetAuditLog::where('timesheet_entry_id', $timesheet->id)
            ->where('action', 'approved')
            ->firstOrFail();

        $this->assertSame($this->admin->id, $auditLog->performed_by);
        $this->assertSame(TimesheetStatus::PendingApproval->value, $auditLog->old_values['status'] ?? null);
        $this->assertSame(TimesheetStatus::Approved->value, $auditLog->new_values['status'] ?? null);
    }

    public function test_admin_updated_hours_recalculates_total_minutes_and_approval_preserves_new_total(): void
    {
        Carbon::setTestNow('2026-11-02 20:00:00');

        // Initial submission: 09:00 to 17:00 (480 minutes)
        $this->actingAs($this->employee)->post(route('employee.timesheets.store'), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $timesheet = TimesheetEntry::where('user_id', $this->employee->id)->latest('id')->firstOrFail();
        $this->assertSame(480, (int) $timesheet->total_minutes);

        // Admin updates hours before approving: 09:00 to 14:15 (5 hours and 15 minutes = 315 minutes)
        $updateResponse = $this->actingAs($this->admin)->put(route('admin.timesheets.update', $timesheet), [
            'branch_id' => $this->branch->id,
            'work_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '14:15',
            'admin_notes' => 'Adjusted departure time due to early closing',
        ]);

        $updateResponse->assertRedirect();
        $timesheet->refresh();
        $this->assertSame(315, (int) $timesheet->total_minutes);
        $this->assertSame(TimesheetStatus::PendingApproval, $timesheet->status);

        // Admin approves the adjusted timesheet
        $approveResponse = $this->actingAs($this->admin)->post(route('admin.timesheets.approve', $timesheet));
        $approveResponse->assertRedirect();

        $timesheet->refresh();
        $this->assertSame(TimesheetStatus::Approved, $timesheet->status);
        $this->assertSame(315, (int) $timesheet->total_minutes);
    }

    public function test_admin_approval_rejects_incomplete_entry_with_null_ends_at(): void
    {
        $service = app(TimesheetService::class);

        $incompleteEntry = TimesheetEntry::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => null,
            'total_minutes' => 0,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // 1. Service level approval rejection
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot approve a timesheet entry without a valid duration');
        $service->approve($incompleteEntry, $this->admin);

        // Status must remain PendingApproval
        $this->assertSame(TimesheetStatus::PendingApproval, $incompleteEntry->fresh()->status);
    }

    public function test_admin_approval_endpoint_redirects_with_error_on_incomplete_entry(): void
    {
        $incompleteEntry = TimesheetEntry::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => null,
            'total_minutes' => 0,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.timesheets.approve', $incompleteEntry));
        $response->assertRedirect();
        $response->assertSessionHasErrors('timesheet');

        $this->assertSame(TimesheetStatus::PendingApproval, $incompleteEntry->fresh()->status);
    }

    public function test_admin_approval_rejects_entry_with_zero_total_minutes(): void
    {
        $service = app(TimesheetService::class);

        $zeroEntry = TimesheetEntry::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'starts_at' => now()->subHours(1),
            'ends_at' => now()->subHours(1)->addSeconds(15),
            'total_minutes' => 0,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot approve a timesheet entry without a valid duration');
        $service->approve($zeroEntry, $this->admin);

        $this->assertSame(TimesheetStatus::PendingApproval, $zeroEntry->fresh()->status);
    }

    public function test_timesheet_service_submit_rejects_admin_user_at_service_level(): void
    {
        $service = app(TimesheetService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only employees can submit timesheets.');

        $service->submit($this->admin, [
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
    }

    public function test_timesheet_table_displays_working_now_only_when_clocked_in_and_ends_at_is_null(): void
    {
        // Active clock-in: ends_at = null, status = ClockedIn -> must show 'Working Now'
        $activeEntry = TimesheetEntry::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'ends_at' => null,
            'status' => TimesheetStatus::ClockedIn,
        ]);

        // Inconsistent entry: ends_at = null, status = Approved -> must NOT show 'Working Now', must show '—'
        $inconsistentEntry = TimesheetEntry::factory()->create([
            'user_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'ends_at' => null,
            'status' => TimesheetStatus::Approved,
        ]);

        $viewActive = view('partials.timesheet-table', ['entries' => collect([$activeEntry])])->render();
        $this->assertTrue(str_contains($viewActive, 'Working Now') || str_contains($viewActive, 'بالعمل الآن'));

        $viewInconsistent = view('partials.timesheet-table', ['entries' => collect([$inconsistentEntry])])->render();
        $this->assertFalse(str_contains($viewInconsistent, 'Working Now'));
        $this->assertFalse(str_contains($viewInconsistent, 'بالعمل الآن'));
        $this->assertTrue(str_contains($viewInconsistent, '—'));
    }

    public function test_zero_minute_clock_out_is_marked_rejected_and_prevented_from_entering_pending_approval(): void
    {
        $service = app(TimesheetService::class);

        Carbon::setTestNow('2026-11-02 10:00:00');
        $entry = $service->clockIn($this->employee, $this->branch->id);

        // Clock out only 15 seconds later (0 minutes elapsed)
        Carbon::setTestNow('2026-11-02 10:00:15');
        $service->clockOut($this->employee);

        $entry->refresh();
        $this->assertSame(0, (int) $entry->total_minutes);
        // Must be marked Rejected, NOT PendingApproval, preventing it from entering approval workflow
        $this->assertSame(TimesheetStatus::Rejected, $entry->status);
        $this->assertNotNull($entry->rejection_reason);
        $this->assertFalse($this->employee->fresh()->isClockedIn());
    }
}

