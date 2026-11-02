<?php

namespace Tests\Feature\Admin;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class EmployeeDeactivationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->branch = Branch::where('slug', 'vancouver')->firstOrFail();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Admin Manager',
            'email' => 'admin.manager@shawarma.test',
        ]);
    }

    public function test_inactive_employee_cannot_update_password_through_existing_session(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => false,
            'password' => Hash::make('CurrentPass123!'),
        ]);

        $response = $this->actingAs($employee)->put(route('password.update'), [
            'current_password' => 'CurrentPass123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        // Intercepted by 'active' middleware, logged out and redirected to login with error
        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // Password remains unchanged
        $this->assertTrue(Hash::check('CurrentPass123!', $employee->fresh()->password));
    }

    public function test_inactive_employee_cannot_access_password_confirmation_routes(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => false,
        ]);

        $getRes = $this->actingAs($employee)->get(route('password.confirm'));
        $getRes->assertRedirect(route('login'));
        $getRes->assertSessionHasErrors('email');
        $this->assertGuest();

        $postRes = $this->actingAs($employee)->post(route('password.confirm'), [
            'password' => 'SomePassword123!',
        ]);
        $postRes->assertRedirect(route('login'));
        $postRes->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_inactive_employee_cannot_access_email_verification_routes(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => false,
            'email_verified_at' => null,
        ]);

        $noticeRes = $this->actingAs($employee)->get(route('verification.notice'));
        $noticeRes->assertRedirect(route('login'));
        $noticeRes->assertSessionHasErrors('email');
        $this->assertGuest();

        $sendRes = $this->actingAs($employee)->post(route('verification.send'));
        $sendRes->assertRedirect(route('login'));
        $sendRes->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_remains_accessible_for_inactive_authenticated_users(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => false,
        ]);

        $response = $this->actingAs($employee)->post(route('logout'));

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_deactivation_deletes_all_database_sessions_belonging_to_that_employee(): void
    {
        config(['session.driver' => 'database']);

        $employee1 = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee2 = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);

        // Insert mock active sessions in the database sessions table
        DB::table('sessions')->insert([
            ['id' => 'sess_emp1_phone', 'user_id' => $employee1->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Phone', 'payload' => 'payload1', 'last_activity' => time()],
            ['id' => 'sess_emp1_laptop', 'user_id' => $employee1->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Laptop', 'payload' => 'payload2', 'last_activity' => time()],
            ['id' => 'sess_emp2_tablet', 'user_id' => $employee2->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Tablet', 'payload' => 'payload3', 'last_activity' => time()],
            ['id' => 'sess_admin_pc', 'user_id' => $this->admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Desktop', 'payload' => 'payload4', 'last_activity' => time()],
        ]);

        $this->assertSame(2, DB::table('sessions')->where('user_id', $employee1->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $employee2->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->admin->id)->count());

        // Admin deactivates employee1
        $initialAdminSessionsCount = DB::table('sessions')->where('user_id', $this->admin->id)->count();
        $response = $this->actingAs($this->admin)->post(route('admin.employees.toggle-status', $employee1));
        $response->assertRedirect();
        $this->assertFalse((bool) $employee1->fresh()->is_active);

        // Employee 1 sessions are immediately purged
        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee1->id)->count());

        // Employee 2 and Admin sessions remain completely intact
        $this->assertSame(1, DB::table('sessions')->where('user_id', $employee2->id)->count());
        $this->assertGreaterThanOrEqual($initialAdminSessionsCount, DB::table('sessions')->where('user_id', $this->admin->id)->count());
    }

    public function test_reactivation_does_not_delete_unrelated_sessions(): void
    {
        config(['session.driver' => 'database']);

        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => false,
        ]);
        $otherEmployee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);

        DB::table('sessions')->insert([
            ['id' => 'sess_other_emp', 'user_id' => $otherEmployee->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Other', 'payload' => 'payload', 'last_activity' => time()],
            ['id' => 'sess_admin', 'user_id' => $this->admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Admin', 'payload' => 'payload', 'last_activity' => time()],
        ]);

        $initialAdminSessionsCount = DB::table('sessions')->where('user_id', $this->admin->id)->count();

        // Admin activates the inactive employee
        $response = $this->actingAs($this->admin)->post(route('admin.employees.toggle-status', $employee));
        $response->assertRedirect();
        $this->assertTrue((bool) $employee->fresh()->is_active);

        // All existing sessions remain intact
        $this->assertSame(1, DB::table('sessions')->where('user_id', $otherEmployee->id)->count());
        $this->assertGreaterThanOrEqual($initialAdminSessionsCount, DB::table('sessions')->where('user_id', $this->admin->id)->count());
    }

    public function test_historical_timesheets_and_audit_logs_remain_intact_upon_deactivation(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        $timesheet = TimesheetEntry::create([
            'user_id' => $employee->id,
            'branch_id' => $this->branch->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->subHours(8),
            'ends_at' => now(),
            'total_minutes' => 480,
            'status' => TimesheetStatus::Approved,
        ]);

        $auditLog = TimesheetAuditLog::create([
            'timesheet_entry_id' => $timesheet->id,
            'action' => 'approved',
            'performed_by' => $this->admin->id,
            'metadata' => ['note' => 'Approved regularly'],
        ]);

        $initialTimesheetCount = TimesheetEntry::where('user_id', $employee->id)->count();
        $initialAuditCount = TimesheetAuditLog::where('timesheet_entry_id', $timesheet->id)->count();
        $this->assertSame(1, $initialTimesheetCount);
        $this->assertSame(1, $initialAuditCount);

        // Admin deactivates employee
        $this->actingAs($this->admin)->post(route('admin.employees.toggle-status', $employee));
        $this->assertFalse((bool) $employee->fresh()->is_active);

        // Records are completely preserved
        $this->assertSame(1, TimesheetEntry::where('user_id', $employee->id)->count());
        $this->assertSame(1, TimesheetAuditLog::where('timesheet_entry_id', $timesheet->id)->count());
        $this->assertSame(480, (int) $timesheet->fresh()->total_minutes);
    }

    public function test_updating_active_employee_to_inactive_deletes_all_their_sessions_and_preserves_others(): void
    {
        config(['session.driver' => 'database']);

        $employee1 = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee1->branches()->attach($this->branch->id);

        $employee2 = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee2->branches()->attach($this->branch->id);

        DB::table('sessions')->insert([
            ['id' => 'sess_update_emp1_phone', 'user_id' => $employee1->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Phone', 'payload' => 'payload1', 'last_activity' => time()],
            ['id' => 'sess_update_emp1_laptop', 'user_id' => $employee1->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Laptop', 'payload' => 'payload2', 'last_activity' => time()],
            ['id' => 'sess_update_emp2_tablet', 'user_id' => $employee2->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Tablet', 'payload' => 'payload3', 'last_activity' => time()],
            ['id' => 'sess_update_admin_pc', 'user_id' => $this->admin->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Desktop', 'payload' => 'payload4', 'last_activity' => time()],
        ]);

        $this->assertSame(2, DB::table('sessions')->where('user_id', $employee1->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $employee2->id)->count());
        $initialAdminCount = DB::table('sessions')->where('user_id', $this->admin->id)->count();

        // Update employee1 to is_active => 0
        $response = $this->actingAs($this->admin)->put(route('admin.employees.update', $employee1), [
            'name' => $employee1->name,
            'email' => $employee1->email,
            'phone' => '1234567890',
            'branch_ids' => [$this->branch->id],
            'is_active' => 0,
        ]);

        $response->assertRedirect(route('admin.employees.index'));
        $response->assertSessionHas('status', 'Employee updated.');
        $this->assertFalse((bool) $employee1->fresh()->is_active);

        // Employee1 sessions are purged
        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee1->id)->count());

        // Employee2 and Admin sessions remain untouched
        $this->assertSame(1, DB::table('sessions')->where('user_id', $employee2->id)->count());
        $this->assertGreaterThanOrEqual($initialAdminCount, DB::table('sessions')->where('user_id', $this->admin->id)->count());
    }

    public function test_updating_employee_while_keeping_active_preserves_their_sessions(): void
    {
        config(['session.driver' => 'database']);

        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        DB::table('sessions')->insert([
            ['id' => 'sess_keep_active_phone', 'user_id' => $employee->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Phone', 'payload' => 'payload1', 'last_activity' => time()],
        ]);

        $this->assertSame(1, DB::table('sessions')->where('user_id', $employee->id)->count());

        $response = $this->actingAs($this->admin)->put(route('admin.employees.update', $employee), [
            'name' => 'Updated Name Active',
            'email' => $employee->email,
            'phone' => '9876543210',
            'branch_ids' => [$this->branch->id],
            'is_active' => 1,
        ]);

        $response->assertRedirect(route('admin.employees.index'));
        $this->assertTrue((bool) $employee->fresh()->is_active);
        $this->assertSame('Updated Name Active', $employee->fresh()->name);

        // Session remains intact
        $this->assertSame(1, DB::table('sessions')->where('user_id', $employee->id)->count());
    }

    public function test_updating_already_inactive_employee_succeeds_without_unintended_effects(): void
    {
        config(['session.driver' => 'database']);

        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => false,
        ]);
        $employee->branches()->attach($this->branch->id);

        $response = $this->actingAs($this->admin)->put(route('admin.employees.update', $employee), [
            'name' => 'Still Inactive Name',
            'email' => $employee->email,
            'branch_ids' => [$this->branch->id],
            'is_active' => 0,
        ]);

        $response->assertRedirect(route('admin.employees.index'));
        $this->assertFalse((bool) $employee->fresh()->is_active);
        $this->assertSame('Still Inactive Name', $employee->fresh()->name);
    }

    public function test_non_database_session_drivers_do_not_cause_errors_on_deactivation(): void
    {
        config(['session.driver' => 'array']);

        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        // Test update deactivation
        $response = $this->actingAs($this->admin)->put(route('admin.employees.update', $employee), [
            'name' => $employee->name,
            'email' => $employee->email,
            'branch_ids' => [$this->branch->id],
            'is_active' => 0,
        ]);
        $response->assertRedirect(route('admin.employees.index'));
        $this->assertFalse((bool) $employee->fresh()->is_active);

        // Test toggleStatus deactivation on a separate active employee
        $employee2 = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee2->branches()->attach($this->branch->id);

        $toggleResponse = $this->actingAs($this->admin)->post(route('admin.employees.toggle-status', $employee2));
        $toggleResponse->assertRedirect();
        $this->assertFalse((bool) $employee2->fresh()->is_active);
    }

    public function test_session_deletion_failure_is_logged_and_does_not_silently_disappear(): void
    {
        Log::spy();
        config(['session.driver' => 'database']);

        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        DB::table('sessions')->insert([
            ['id' => 'sess_fail_test', 'user_id' => $employee->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'payload' => 'payload', 'last_activity' => time()],
        ]);

        // Add a trigger on sessions table to throw on DELETE (driver-aware for SQLite and MySQL)
        DB::statement('DROP TRIGGER IF EXISTS fail_session_delete;');
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("CREATE TRIGGER fail_session_delete BEFORE DELETE ON sessions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated delete error'; END;");
        } else {
            DB::statement("CREATE TRIGGER fail_session_delete BEFORE DELETE ON sessions BEGIN SELECT RAISE(FAIL, 'Simulated delete error'); END;");
        }

        try {
            $response = $this->actingAs($this->admin)->put(route('admin.employees.update', $employee), [
                'name' => $employee->name,
                'email' => $employee->email,
                'branch_ids' => [$this->branch->id],
                'is_active' => 0,
            ]);

            $response->assertRedirect(route('admin.employees.index'));
            $this->assertFalse((bool) $employee->fresh()->is_active);

            Log::shouldHaveReceived('warning')
                ->once()
                ->with(
                    'Failed to revoke database sessions for deactivated employee.',
                    Mockery::on(function (array $context) use ($employee) {
                        return isset($context['employee_id'])
                            && $context['employee_id'] === $employee->id
                            && isset($context['error'])
                            && ! isset($context['payload'])
                            && ! isset($context['id']);
                    })
                );
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS fail_session_delete;');
        }
    }
}
