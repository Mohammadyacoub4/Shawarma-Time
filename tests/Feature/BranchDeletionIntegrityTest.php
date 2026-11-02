<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetAuditLog;
use App\Models\TimesheetEntry;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchDeletionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement A: Branch with timesheet history cannot be deleted via Eloquent.
     * Branch, timesheet entries, and audit logs must remain intact.
     */
    public function test_branch_with_timesheet_history_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test Branch A',
            'slug' => 'test-branch-a',
        ]);
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
        ]);

        $auditLog = TimesheetAuditLog::create([
            'timesheet_entry_id' => $timesheet->id,
            'performed_by' => $employee->id,
            'action' => 'submitted',
            'notes' => 'Submitted timesheet entry',
        ]);

        $exceptionThrown = false;
        try {
            $branch->delete();
        } catch (DomainException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Cannot delete a branch with associated timesheet records', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected DomainException was not thrown when deleting branch with timesheets.');

        // Assert branch, timesheet entry, and audit log remain in the database
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('timesheet_entries', ['id' => $timesheet->id]);
        $this->assertDatabaseHas('timesheet_audit_logs', ['id' => $auditLog->id]);
    }

    /**
     * Requirement B: Branch with shifts cannot be deleted via Eloquent.
     * Branch and shift records must remain intact.
     */
    public function test_branch_with_shifts_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test Branch B',
            'slug' => 'test-branch-b',
        ]);
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $shift = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'schedule_week_id' => null,
        ]);

        $exceptionThrown = false;
        try {
            $branch->delete();
        } catch (DomainException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Cannot delete a branch with associated shift records', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected DomainException was not thrown when deleting branch with shifts.');

        // Assert branch and shift remain in the database
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }

    /**
     * Requirement C: Branch with schedule weeks cannot be deleted via Eloquent.
     * Branch and schedule week records must remain intact even if week has no shifts.
     */
    public function test_branch_with_schedule_weeks_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test Branch C',
            'slug' => 'test-branch-c',
        ]);

        $week = ScheduleWeek::factory()->create([
            'branch_id' => $branch->id,
        ]);

        $exceptionThrown = false;
        try {
            $branch->delete();
        } catch (DomainException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Cannot delete a branch with associated schedule weeks', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected DomainException was not thrown when deleting branch with schedule weeks.');

        // Assert branch and schedule week remain in the database
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('schedule_weeks', ['id' => $week->id]);
    }

    /**
     * Requirement D: Empty branch without timesheets, shifts, or schedule weeks can be deleted.
     * branch_user pivot records cascade safely as intended.
     */
    public function test_empty_branch_can_be_deleted_and_cascades_pivot_assignments(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test Branch Empty',
            'slug' => 'test-branch-empty',
        ]);
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('branch_user', [
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
        ]);

        // Empty branch deletion must succeed
        $deleted = $branch->delete();
        $this->assertTrue($deleted);

        // Branch is deleted
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);

        // Pivot record in branch_user is cascade-deleted as intended
        $this->assertDatabaseMissing('branch_user', [
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
        ]);

        // Employee record itself remains completely intact
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    /**
     * Requirement E: Database-level foreign key restriction prevents deletion even when
     * bypassing the Eloquent deleting event guard via direct query builder deletion.
     */
    public function test_database_foreign_key_restricts_branch_deletion_with_timesheet_entries(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test DB Restrict Branch Timesheets',
            'slug' => 'test-db-restrict-branch-timesheets',
        ]);
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
        ]);

        $auditLog = TimesheetAuditLog::create([
            'timesheet_entry_id' => $timesheet->id,
            'performed_by' => $employee->id,
            'action' => 'submitted',
        ]);

        // Attempt direct query builder deletion (bypassing Eloquent event listener)
        $queryExceptionThrown = false;
        try {
            DB::table('branches')->where('id', $branch->id)->delete();
        } catch (QueryException $e) {
            $queryExceptionThrown = true;
        }

        $this->assertTrue($queryExceptionThrown, 'Expected QueryException (foreign key violation) was not thrown by DB engine.');

        // Verify all records remain intact in database
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('timesheet_entries', ['id' => $timesheet->id]);
        $this->assertDatabaseHas('timesheet_audit_logs', ['id' => $auditLog->id]);
    }

    /**
     * Requirement E: Database-level foreign key restriction prevents deletion when shifts exist.
     */
    public function test_database_foreign_key_restricts_branch_deletion_with_shifts(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test DB Restrict Branch Shifts',
            'slug' => 'test-db-restrict-branch-shifts',
        ]);
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $shift = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'schedule_week_id' => null,
        ]);

        // Attempt direct query builder deletion
        $queryExceptionThrown = false;
        try {
            DB::table('branches')->where('id', $branch->id)->delete();
        } catch (QueryException $e) {
            $queryExceptionThrown = true;
        }

        $this->assertTrue($queryExceptionThrown, 'Expected QueryException (foreign key violation) was not thrown for shifts.');

        // Verify records remain intact in database
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }

    /**
     * Requirement E: Database-level foreign key restriction prevents deletion when schedule weeks exist.
     */
    public function test_database_foreign_key_restricts_branch_deletion_with_schedule_weeks(): void
    {
        $branch = Branch::factory()->create([
            'name' => 'Test DB Restrict Branch Weeks',
            'slug' => 'test-db-restrict-branch-weeks',
        ]);

        $week = ScheduleWeek::factory()->create([
            'branch_id' => $branch->id,
        ]);

        // Attempt direct query builder deletion
        $queryExceptionThrown = false;
        try {
            DB::table('branches')->where('id', $branch->id)->delete();
        } catch (QueryException $e) {
            $queryExceptionThrown = true;
        }

        $this->assertTrue($queryExceptionThrown, 'Expected QueryException (foreign key violation) was not thrown for schedule weeks.');

        // Verify records remain intact in database
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('schedule_weeks', ['id' => $week->id]);
    }

    /**
     * Migration down() and up() execution test:
     * Verifies that down() cleanly restores cascade behavior and up() restores restriction.
     */
    public function test_migration_rollback_and_reapply_executes_cleanly(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_restrict_branch_deletion_on_historical_tables.php');

        // Rollback restores CASCADE
        $migration->down();

        // With CASCADE restored, direct query builder deletion succeeds and cascades timesheets
        $branch = Branch::factory()->create(['slug' => 'test-rollback-cascade-branch']);
        $employee = User::factory()->employee()->create();
        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
        ]);

        DB::table('branches')->where('id', $branch->id)->delete();
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
        $this->assertDatabaseMissing('timesheet_entries', ['id' => $timesheet->id]);

        // Reapply migration restores RESTRICT
        $migration->up();

        $branchRestricted = Branch::factory()->create(['slug' => 'test-reapply-restrict-branch']);
        TimesheetEntry::factory()->create([
            'branch_id' => $branchRestricted->id,
            'user_id' => $employee->id,
        ]);

        $queryExceptionThrown = false;
        try {
            DB::table('branches')->where('id', $branchRestricted->id)->delete();
        } catch (QueryException $e) {
            $queryExceptionThrown = true;
        }

        $this->assertTrue($queryExceptionThrown);
        $this->assertDatabaseHas('branches', ['id' => $branchRestricted->id]);
    }
}
