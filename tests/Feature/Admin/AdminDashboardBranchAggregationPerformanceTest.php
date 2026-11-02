<?php

namespace Tests\Feature\Admin;

use App\Enums\TimesheetStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDashboardBranchAggregationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_hours_by_branch_exact_totals_zero_handling_and_status_filtering(): void
    {
        $admin = User::factory()->admin()->create();

        $branchA = Branch::factory()->create(['name' => 'Branch A']);
        $branchB = Branch::factory()->create(['name' => 'Branch B']);
        $branchC = Branch::factory()->create(['name' => 'Branch C']);

        $activeEmployee = User::factory()->create([
            'role' => UserRole::Employee,
            'is_active' => true,
        ]);
        $inactiveEmployee = User::factory()->create([
            'role' => UserRole::Employee,
            'is_active' => false,
        ]);

        // Fix an explicit reference Monday so dates inside and outside week are unambiguous
        $selectedDate = '2026-05-13'; // Wednesday
        $weekStart = '2026-05-11';    // Monday
        $midWeek = '2026-05-13';      // Wednesday
        $beforeWeekEnd = '2026-05-15';// Friday
        $outsideWeek = '2026-05-04';  // Previous week

        // Branch A: Active employee approved (120 min) + Inactive employee approved (60 min) = 180 min
        TimesheetEntry::create([
            'user_id' => $activeEmployee->id,
            'branch_id' => $branchA->id,
            'work_date' => $weekStart,
            'starts_at' => '2026-05-11 08:00:00',
            'ends_at' => '2026-05-11 10:00:00',
            'total_minutes' => 120,
            'status' => TimesheetStatus::Approved,
        ]);

        TimesheetEntry::create([
            'user_id' => $inactiveEmployee->id,
            'branch_id' => $branchA->id,
            'work_date' => $midWeek,
            'starts_at' => '2026-05-13 08:00:00',
            'ends_at' => '2026-05-13 09:00:00',
            'total_minutes' => 60,
            'status' => TimesheetStatus::Approved,
        ]);

        // Branch A: Outside week date should NOT be included
        TimesheetEntry::create([
            'user_id' => $activeEmployee->id,
            'branch_id' => $branchA->id,
            'work_date' => $outsideWeek,
            'starts_at' => '2026-05-04 08:00:00',
            'ends_at' => '2026-05-04 10:00:00',
            'total_minutes' => 120,
            'status' => TimesheetStatus::Approved,
        ]);

        // Branch B: Approved (90 min) + PendingApproval (30 min) + Rejected (45 min) + ClockedIn (0 min) = only 90 min
        TimesheetEntry::create([
            'user_id' => $activeEmployee->id,
            'branch_id' => $branchB->id,
            'work_date' => $beforeWeekEnd,
            'starts_at' => '2026-05-15 10:00:00',
            'ends_at' => '2026-05-15 11:30:00',
            'total_minutes' => 90,
            'status' => TimesheetStatus::Approved,
        ]);

        TimesheetEntry::create([
            'user_id' => $activeEmployee->id,
            'branch_id' => $branchB->id,
            'work_date' => $midWeek,
            'starts_at' => '2026-05-13 12:00:00',
            'ends_at' => '2026-05-13 12:30:00',
            'total_minutes' => 30,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        TimesheetEntry::create([
            'user_id' => $activeEmployee->id,
            'branch_id' => $branchB->id,
            'work_date' => $midWeek,
            'starts_at' => '2026-05-13 13:00:00',
            'ends_at' => '2026-05-13 13:45:00',
            'total_minutes' => 45,
            'status' => TimesheetStatus::Rejected,
        ]);

        TimesheetEntry::create([
            'user_id' => $activeEmployee->id,
            'branch_id' => $branchB->id,
            'work_date' => $midWeek,
            'starts_at' => '2026-05-13 14:00:00',
            'ends_at' => null,
            'total_minutes' => 0,
            'status' => TimesheetStatus::ClockedIn,
        ]);

        // Branch C: Has NO timesheet entries at all -> must be present with integer 0

        $response = $this->actingAs($admin)->get(route('admin.dashboard', ['date' => $selectedDate]));
        $response->assertOk();

        $approvedByBranch = $response->viewData('approvedByBranch');

        $this->assertIsArray($approvedByBranch);
        $this->assertCount(3, $approvedByBranch);

        $this->assertSame(180, $approvedByBranch[$branchA->id]);
        $this->assertSame(90, $approvedByBranch[$branchB->id]);
        $this->assertSame(0, $approvedByBranch[$branchC->id]);
        $this->assertIsInt($approvedByBranch[$branchC->id]);
    }

    public function test_branch_aggregation_query_count_does_not_grow_with_branch_count(): void
    {
        $admin = User::factory()->admin()->create();

        // Create 2 branches
        Branch::factory()->count(2)->create();
        $selectedDate = '2026-05-13';

        $queryCountWith2Branches = 0;
        DB::listen(function ($query) use (&$queryCountWith2Branches) {
            if (str_contains($query->sql, 'total_minutes') && str_contains($query->sql, 'timesheet_entries')) {
                $queryCountWith2Branches++;
            }
        });

        $this->actingAs($admin)->get(route('admin.dashboard', ['date' => $selectedDate]))->assertOk();

        // Exactly 1 aggregation query should have run for the 2 branches
        $this->assertSame(1, $queryCountWith2Branches, 'Expected exactly 1 aggregate query for 2 branches.');

        // Now create 8 more branches (10 branches total)
        Branch::factory()->count(8)->create();

        $queryCountWith10Branches = 0;
        DB::listen(function ($query) use (&$queryCountWith10Branches) {
            if (str_contains($query->sql, 'total_minutes') && str_contains($query->sql, 'timesheet_entries')) {
                $queryCountWith10Branches++;
            }
        });

        $this->actingAs($admin)->get(route('admin.dashboard', ['date' => $selectedDate]))->assertOk();

        // Exactly 1 aggregation query should run for 10 branches as well (O(1) query complexity)
        $this->assertSame(1, $queryCountWith10Branches, 'Expected exactly 1 aggregate query even when scaled to 10 branches.');
    }
}
