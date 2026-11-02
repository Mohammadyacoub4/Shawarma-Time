<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\BranchTime;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftDeletionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requirement A: Eloquent deletion.
     * Shift with linked timesheet cannot be deleted.
     * Shift and timesheet entry remain intact with shift_id unchanged.
     */
    public function test_shift_with_linked_timesheet_cannot_be_deleted_via_eloquent(): void
    {
        $branch = Branch::factory()->create();
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $shift = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'schedule_week_id' => null,
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00')),
        ]);

        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00')),
        ]);

        $exceptionThrown = false;
        try {
            $shift->delete();
        } catch (DomainException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Cannot delete a shift that has associated timesheet records', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected DomainException was not thrown when deleting shift with timesheet.');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('timesheet_entries', [
            'id' => $timesheet->id,
            'shift_id' => $shift->id,
        ]);
    }

    /**
     * Requirement B: HTTP deletion.
     * Admin DELETE request is redirected back with an error.
     * Shift and linked timesheet remain intact.
     */
    public function test_admin_http_delete_request_is_redirected_back_with_error_when_linked_timesheet_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $branch = Branch::factory()->create();
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $shift = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'schedule_week_id' => null,
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00')),
        ]);

        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '17:00')),
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.schedules.shifts.destroy', $shift));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['shift']);

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('timesheet_entries', [
            'id' => $timesheet->id,
            'shift_id' => $shift->id,
        ]);
    }

    /**
     * Requirement C: Unlinked shift deletion succeeds via Eloquent and HTTP.
     */
    public function test_unlinked_shift_can_be_deleted_via_eloquent_and_http(): void
    {
        $admin = User::factory()->admin()->create();
        $branch = Branch::factory()->create();
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        // 1. Eloquent deletion of unlinked shift
        $shift1 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'schedule_week_id' => null,
        ]);
        $this->assertTrue($shift1->delete());
        $this->assertDatabaseMissing('shifts', ['id' => $shift1->id]);

        // 2. HTTP deletion of unlinked shift
        $shift2 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'schedule_week_id' => null,
        ]);
        $response = $this->actingAs($admin)->delete(route('admin.schedules.shifts.destroy', $shift2));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('shifts', ['id' => $shift2->id]);
    }

    /**
     * Requirement D: Clear Week with linked records.
     * Service operation throws DomainException, no partial deletion.
     * HTTP operation redirects with a useful error.
     */
    public function test_clear_week_with_linked_records_prevents_partial_deletion_at_service_and_http_levels(): void
    {
        $admin = User::factory()->admin()->create();
        $branch = Branch::factory()->create();
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $weekStart = '2026-11-02'; // Monday

        // Create 3 shifts in the week
        $shift1 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'work_date' => '2026-11-02',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-02', '13:00')),
        ]);
        $shift2 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'work_date' => '2026-11-03',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-03', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-03', '13:00')),
        ]);
        $shift3 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'work_date' => '2026-11-04',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-04', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-04', '13:00')),
        ]);

        // Link only shift2 to a timesheet
        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'shift_id' => $shift2->id,
            'work_date' => '2026-11-03',
            'starts_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-03', '09:00')),
            'ends_at' => BranchTime::toUtc(BranchTime::combineLocalDateAndTime('2026-11-03', '13:00')),
        ]);

        // 1. Service operation must throw DomainException
        $scheduleService = app(ScheduleService::class);
        $exceptionThrown = false;
        try {
            $scheduleService->clearWeek($branch, $weekStart);
        } catch (DomainException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString('Cannot clear week: one or more shifts have associated timesheet records', $e->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected DomainException was not thrown by clearWeek service.');

        // Assert ALL shifts remain intact (no partial deletion of shift1 or shift3)
        $this->assertDatabaseHas('shifts', ['id' => $shift1->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift2->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift3->id]);
        $this->assertDatabaseHas('timesheet_entries', ['id' => $timesheet->id, 'shift_id' => $shift2->id]);

        // 2. HTTP operation must redirect with useful error and not delete anything
        $response = $this->actingAs($admin)->post(route('admin.schedules.clear-week'), [
            'branch_id' => $branch->id,
            'week_start' => $weekStart,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['shift']);

        // Assert all shifts still intact
        $this->assertDatabaseHas('shifts', ['id' => $shift1->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift2->id]);
        $this->assertDatabaseHas('shifts', ['id' => $shift3->id]);
    }

    /**
     * Requirement E: Clear Week without linked records.
     * All unlinked shifts in week are deleted, correct count returned.
     */
    public function test_clear_week_without_linked_records_deletes_all_shifts_and_returns_count(): void
    {
        $branch = Branch::factory()->create();
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $weekStart = '2026-11-02';

        $shift1 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'work_date' => '2026-11-02',
        ]);
        $shift2 = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'work_date' => '2026-11-04',
        ]);

        $scheduleService = app(ScheduleService::class);
        $deletedCount = $scheduleService->clearWeek($branch, $weekStart);

        $this->assertEquals(2, $deletedCount);
        $this->assertDatabaseMissing('shifts', ['id' => $shift1->id]);
        $this->assertDatabaseMissing('shifts', ['id' => $shift2->id]);
    }

    /**
     * Requirement F: Database-level protection.
     * Direct query-builder delete bypassing Eloquent throws QueryException.
     * Shift and timesheet entry remain intact.
     */
    public function test_database_foreign_key_restricts_shift_deletion_when_timesheet_exists(): void
    {
        $branch = Branch::factory()->create();
        $employee = User::factory()->employee()->create();
        $employee->branches()->attach($branch->id);

        $shift = Shift::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
        ]);

        $timesheet = TimesheetEntry::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
        ]);

        // Attempt direct query builder deletion bypassing Eloquent deleting event
        $queryExceptionThrown = false;
        try {
            DB::table('shifts')->where('id', $shift->id)->delete();
        } catch (QueryException $e) {
            $queryExceptionThrown = true;
        }

        $this->assertTrue($queryExceptionThrown, 'Expected QueryException was not thrown on direct DB shift delete.');
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertDatabaseHas('timesheet_entries', [
            'id' => $timesheet->id,
            'shift_id' => $shift->id,
        ]);
    }

    /**
     * Requirement G: Migration reversibility.
     * up() applies RESTRICT.
     * down() restores SET NULL.
     * timesheet_entries_shift_id_unique index is preserved.
     */
    public function test_migration_reversibility_and_unique_index_preservation(): void
    {
        $migration = require database_path('migrations/2026_11_02_000002_restrict_shift_deletion_on_timesheet_entries.php');

        // Rollback restores SET NULL
        $migration->down();

        $shift1 = Shift::factory()->create();
        $timesheet1 = TimesheetEntry::factory()->create([
            'user_id' => $shift1->user_id,
            'branch_id' => $shift1->branch_id,
            'shift_id' => $shift1->id,
        ]);

        // With SET NULL restored, raw DB deletion succeeds and sets shift_id = null
        DB::table('shifts')->where('id', $shift1->id)->delete();
        $this->assertDatabaseMissing('shifts', ['id' => $shift1->id]);
        $this->assertDatabaseHas('timesheet_entries', [
            'id' => $timesheet1->id,
            'shift_id' => null,
        ]);

        // Reapply migration restores RESTRICT
        $migration->up();

        $shift2 = Shift::factory()->create();
        $timesheet2 = TimesheetEntry::factory()->create([
            'user_id' => $shift2->user_id,
            'branch_id' => $shift2->branch_id,
            'shift_id' => $shift2->id,
        ]);

        $queryExceptionThrown = false;
        try {
            DB::table('shifts')->where('id', $shift2->id)->delete();
        } catch (QueryException $e) {
            $queryExceptionThrown = true;
        }

        $this->assertTrue($queryExceptionThrown, 'RESTRICT was not reapplied after migration up().');
        $this->assertDatabaseHas('shifts', ['id' => $shift2->id]);
        $this->assertDatabaseHas('timesheet_entries', [
            'id' => $timesheet2->id,
            'shift_id' => $shift2->id,
        ]);

        // Verify timesheet_entries_shift_id_unique is still enforced
        $uniqueExceptionThrown = false;
        try {
            TimesheetEntry::factory()->create([
                'user_id' => $shift2->user_id,
                'branch_id' => $shift2->branch_id,
                'shift_id' => $shift2->id,
            ]);
        } catch (QueryException $e) {
            $uniqueExceptionThrown = true;
        }

        $this->assertTrue($uniqueExceptionThrown, 'Unique constraint on shift_id was lost after migration.');
    }
}

