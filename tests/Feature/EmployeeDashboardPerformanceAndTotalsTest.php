<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ReportService;
use App\Support\BranchTime;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeDashboardPerformanceAndTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $vancouver;
    private Branch $richmond;
    private ReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->vancouver = Branch::where('slug', 'vancouver')->firstOrFail();
        $this->richmond = Branch::where('slug', 'richmond')->firstOrFail();
        $this->reportService = app(ReportService::class);
    }

    public function test_employee_hour_totals_returns_integer_zeroes_when_no_approved_entries_exist(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);

        $reference = Carbon::parse('2026-11-04 12:00:00', 'Canada/Pacific'); // Wednesday
        $totals = $this->reportService->employeeHourTotals($employee->id, $reference);

        $this->assertIsArray($totals);
        $this->assertSame(0, $totals['daily_approved_minutes']);
        $this->assertSame(0, $totals['weekly_approved_minutes']);
        $this->assertSame(0, $totals['monthly_approved_minutes']);
    }

    public function test_employee_hour_totals_excludes_pending_rejected_and_clocked_in_entries(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);

        $reference = Carbon::parse('2026-11-04 12:00:00', 'Canada/Pacific'); // Wednesday
        $dateStr = '2026-11-04';

        // Pending
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => $dateStr,
            'total_minutes' => 120,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // Rejected
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => $dateStr,
            'total_minutes' => 180,
            'status' => TimesheetStatus::Rejected,
        ]);

        // ClockedIn
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => $dateStr,
            'total_minutes' => 60,
            'status' => TimesheetStatus::ClockedIn,
        ]);

        $totals = $this->reportService->employeeHourTotals($employee->id, $reference);

        $this->assertSame(0, $totals['daily_approved_minutes']);
        $this->assertSame(0, $totals['weekly_approved_minutes']);
        $this->assertSame(0, $totals['monthly_approved_minutes']);
    }

    public function test_employee_hour_totals_calculates_daily_weekly_and_monthly_approved_totals_correctly(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);

        // Week of Nov 2 to Nov 8, 2026:
        // Reference is Wednesday, Nov 4, 2026
        $reference = Carbon::parse('2026-11-04 12:00:00', 'Canada/Pacific');

        // Entry 1: Today (Nov 4) -> 120 mins
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-04',
            'total_minutes' => 120,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry 2: Yesterday (Nov 3) -> 60 mins (Same week, same month)
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-03',
            'total_minutes' => 60,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry 3: Later this week (Nov 6) -> 90 mins (Same week, same month)
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-06',
            'total_minutes' => 90,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry 4: Different week but same month (Nov 12) -> 200 mins
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-12',
            'total_minutes' => 200,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry 5: Different month (Oct 28) -> 300 mins
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-28',
            'total_minutes' => 300,
            'status' => TimesheetStatus::Approved,
        ]);

        $totals = $this->reportService->employeeHourTotals($employee->id, $reference);

        // Daily: Nov 4 only = 120
        $this->assertSame(120, $totals['daily_approved_minutes']);
        // Weekly: Nov 3 (60) + Nov 4 (120) + Nov 6 (90) = 270
        $this->assertSame(270, $totals['weekly_approved_minutes']);
        // Monthly: Nov 3 (60) + Nov 4 (120) + Nov 6 (90) + Nov 12 (200) = 470
        $this->assertSame(470, $totals['monthly_approved_minutes']);
    }

    public function test_employee_hour_totals_handles_week_crossing_month_boundary_correctly(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);

        // Week: Monday Oct 26, 2026 to Sunday Nov 1, 2026
        // Reference is Sunday, Nov 1, 2026 (Nov month has just started)
        $reference = Carbon::parse('2026-11-01 15:00:00', 'Canada/Pacific');

        // Entry in October (same week, previous month)
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-28',
            'total_minutes' => 150,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry on Nov 1 (today, same week, current month)
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-01',
            'total_minutes' => 80,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry on Nov 15 (future week, same month)
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-15',
            'total_minutes' => 100,
            'status' => TimesheetStatus::Approved,
        ]);

        $totals = $this->reportService->employeeHourTotals($employee->id, $reference);

        // Daily: Nov 1 = 80
        $this->assertSame(80, $totals['daily_approved_minutes']);
        // Weekly: Oct 28 (150) + Nov 1 (80) = 230
        $this->assertSame(230, $totals['weekly_approved_minutes']);
        // Monthly: Nov 1 (80) + Nov 15 (100) = 180 (Oct 28 excluded from Nov monthly total)
        $this->assertSame(180, $totals['monthly_approved_minutes']);
    }

    public function test_employee_hour_totals_handles_first_and_last_day_of_month_boundaries_and_does_not_mutate_reference(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);

        // Scenario 1: Reference is last day of month: Saturday, Oct 31, 2026
        $refOct31 = Carbon::parse('2026-10-31 16:00:00', 'Canada/Pacific');
        $originalTimestamp = $refOct31->timestamp;

        // Entry on Oct 31 -> 110 mins
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-10-31',
            'total_minutes' => 110,
            'status' => TimesheetStatus::Approved,
        ]);

        // Entry on Nov 1 (the exclusive end for October) -> 200 mins
        TimesheetEntry::factory()->for($employee)->for($this->vancouver)->create([
            'work_date' => '2026-11-01',
            'total_minutes' => 200,
            'status' => TimesheetStatus::Approved,
        ]);

        $totalsOct31 = $this->reportService->employeeHourTotals($employee->id, $refOct31);

        // Reference Carbon instance must not be mutated
        $this->assertSame($originalTimestamp, $refOct31->timestamp);

        // Daily: Oct 31 only = 110
        $this->assertSame(110, $totalsOct31['daily_approved_minutes']);
        // Monthly: Oct 31 (110) included; Nov 1 (200) strictly excluded
        $this->assertSame(110, $totalsOct31['monthly_approved_minutes']);

        // Scenario 2: Direct raw DB insert with raw YYYY-MM-DD (simulating non-timestamp SQLite/raw insert)
        DB::table('timesheet_entries')->insert([
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-10-01', // First day of October
            'starts_at' => '2026-10-01 10:00:00',
            'ends_at' => '2026-10-01 18:00:00',
            'total_minutes' => 480,
            'status' => TimesheetStatus::Approved->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $refOct1 = Carbon::parse('2026-10-01 09:00:00', 'Canada/Pacific');
        $totalsOct1 = $this->reportService->employeeHourTotals($employee->id, $refOct1);

        $this->assertSame(480, $totalsOct1['daily_approved_minutes']);
        // Monthly October: 480 (Oct 1) + 110 (Oct 31) = 590
        $this->assertSame(590, $totalsOct1['monthly_approved_minutes']);
    }

    public function test_employee_hour_totals_executes_single_aggregate_query(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);

        $reference = Carbon::parse('2026-11-04 12:00:00', 'Canada/Pacific');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->reportService->employeeHourTotals($employee->id, $reference);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $aggregateQueries = array_filter($queries, function ($q) {
            return str_contains(strtolower($q['query']), 'daily_approved_minutes');
        });

        $this->assertCount(1, $aggregateQueries, 'employeeHourTotals should execute exactly one aggregate query');
    }

    public function test_employee_dashboard_loads_for_employee_with_zero_branches(): void
    {
        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();
        $response->assertViewHas('userBranches', fn ($branches) => $branches->isEmpty());
        $response->assertViewHas('userBranch', null);
        $response->assertViewHas('publishedWeeks', fn ($weeks) => $weeks->isEmpty());
    }

    public function test_employee_dashboard_loads_for_employee_with_one_branch(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->richmond->id]);

        ScheduleWeek::create([
            'branch_id' => $this->richmond->id,
            'week_start' => now('Canada/Pacific')->startOfWeek()->toDateString(),
            'is_published' => true,
        ]);

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();
        $response->assertViewHas('userBranches', fn ($branches) => $branches->count() === 1 && $branches->first()->id === $this->richmond->id);
        $response->assertViewHas('userBranch', fn ($b) => $b->id === $this->richmond->id);
        $response->assertViewHas('publishedWeeks', fn ($weeks) => $weeks->count() === 1);
        $response->assertSee($this->richmond->name);
    }

    public function test_employee_dashboard_loads_for_employee_with_multiple_branches_and_preserves_dropdown_order(): void
    {
        $employee = User::factory()->employee()->create();
        // Vancouver and Richmond: Vancouver alphabetically comes after Richmond (R then V)
        $employee->branches()->sync([$this->vancouver->id, $this->richmond->id]);

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        $response->assertViewHas('userBranches', function ($branches) {
            $names = $branches->pluck('name')->toArray();
            $sorted = $names;
            sort($sorted);
            return $names === $sorted && count($names) === 2;
        });

        // Dropdown in HTML contains both branch names
        $response->assertSee($this->richmond->name);
        $response->assertSee($this->vancouver->name);
    }

    public function test_employee_dashboard_fetches_branch_pivot_data_only_once(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id, $this->richmond->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Check queries joining branch_user
        $branchUserQueries = array_filter($queries, function ($q) {
            $sql = strtolower($q['query']);
            return str_contains($sql, 'branch_user') && str_contains($sql, 'branches');
        });

        $this->assertCount(1, $branchUserQueries, 'Employee dashboard must query branch_user relationship exactly once');
    }

    public function test_overnight_shift_displayed_and_preselected_after_midnight(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id, $this->richmond->id]);
        $tz = BranchTime::timezoneFor($this->vancouver);

        // Overnight shift: Sunday 2026-11-01 22:00 to Monday 2026-11-02 06:00
        $week = ScheduleWeek::factory()->for($this->vancouver)->published()->create([
            'week_start' => '2026-10-26',
        ]);

        $overnightShift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-01',
            'starts_at' => Carbon::parse('2026-11-01 22:00:00', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-11-02 06:00:00', $tz)->utc(),
            'grace_minutes' => 15,
        ]);

        // Freeze time to Monday 01:00 (active overnight shift)
        Carbon::setTestNow(Carbon::parse('2026-11-02 01:00:00', $tz)->utc());

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        // todayShift must be the overnight shift
        $response->assertViewHas('todayShift', fn ($shift) => $shift !== null && $shift->id === $overnightShift->id);

        // Vancouver branch must be preselected in clock-in dropdown
        $response->assertSee('<option value="'.$this->vancouver->id.'" selected>', false);

        // upcomingShifts must include this ongoing shift
        $response->assertViewHas('upcomingShifts', fn ($shifts) => $shifts->contains('id', $overnightShift->id));

        Carbon::setTestNow();
    }

    public function test_split_shift_displays_morning_shift_at_seven_thirty(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->richmond->id, $this->vancouver->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $today = '2026-11-02';
        $weekRich = ScheduleWeek::factory()->for($this->richmond)->published()->create([
            'week_start' => '2026-11-02',
        ]);
        $weekVan = ScheduleWeek::factory()->for($this->vancouver)->published()->create([
            'week_start' => '2026-11-02',
        ]);

        $morningShift = Shift::create([
            'schedule_week_id' => $weekRich->id,
            'user_id' => $employee->id,
            'branch_id' => $this->richmond->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 08:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 12:00:00", $tz)->utc(),
        ]);

        $afternoonShift = Shift::create([
            'schedule_week_id' => $weekVan->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 16:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 20:00:00", $tz)->utc(),
        ]);

        // Freeze time to 07:30 (30 mins before morning shift)
        Carbon::setTestNow(Carbon::parse("{$today} 07:30:00", $tz)->utc());

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        // todayShift must be Richmond morning shift
        $response->assertViewHas('todayShift', fn ($shift) => $shift !== null && $shift->id === $morningShift->id);

        // Richmond branch must be preselected
        $response->assertSee('<option value="'.$this->richmond->id.'" selected>', false);

        Carbon::setTestNow();
    }

    public function test_split_shift_switches_to_afternoon_shift_at_fifteen_thirty(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->richmond->id, $this->vancouver->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $today = '2026-11-02';
        $weekRich = ScheduleWeek::factory()->for($this->richmond)->published()->create([
            'week_start' => '2026-11-02',
        ]);
        $weekVan = ScheduleWeek::factory()->for($this->vancouver)->published()->create([
            'week_start' => '2026-11-02',
        ]);

        $morningShift = Shift::create([
            'schedule_week_id' => $weekRich->id,
            'user_id' => $employee->id,
            'branch_id' => $this->richmond->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 08:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 12:00:00", $tz)->utc(),
        ]);

        $afternoonShift = Shift::create([
            'schedule_week_id' => $weekVan->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 16:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 20:00:00", $tz)->utc(),
        ]);

        // Freeze time to 15:30 (morning shift ended at 12:00; afternoon shift starts at 16:00)
        Carbon::setTestNow(Carbon::parse("{$today} 15:30:00", $tz)->utc());

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        // todayShift must switch to Vancouver afternoon shift
        $response->assertViewHas('todayShift', fn ($shift) => $shift !== null && $shift->id === $afternoonShift->id);

        // Vancouver branch must be preselected
        $response->assertSee('<option value="'.$this->vancouver->id.'" selected>', false);

        Carbon::setTestNow();
    }

    public function test_after_all_shifts_end_today_shift_is_null_and_upcoming_excludes_expired(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->richmond->id, $this->vancouver->id]);
        $tz = BranchTime::timezoneFor($this->richmond);

        $today = '2026-11-02';
        $weekRich = ScheduleWeek::factory()->for($this->richmond)->published()->create([
            'week_start' => '2026-11-02',
        ]);
        $weekVan = ScheduleWeek::factory()->for($this->vancouver)->published()->create([
            'week_start' => '2026-11-02',
        ]);

        $morningShift = Shift::create([
            'schedule_week_id' => $weekRich->id,
            'user_id' => $employee->id,
            'branch_id' => $this->richmond->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 08:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 12:00:00", $tz)->utc(),
        ]);

        $afternoonShift = Shift::create([
            'schedule_week_id' => $weekVan->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 16:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 20:00:00", $tz)->utc(),
        ]);

        // Freeze time to 21:00 (both shifts have ended)
        Carbon::setTestNow(Carbon::parse("{$today} 21:00:00", $tz)->utc());

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        // todayShift must be null
        $response->assertViewHas('todayShift', null);

        // upcomingShifts must exclude both expired shifts
        $response->assertViewHas('upcomingShifts', function ($shifts) use ($morningShift, $afternoonShift) {
            return ! $shifts->contains('id', $morningShift->id)
                && ! $shifts->contains('id', $afternoonShift->id);
        });

        Carbon::setTestNow();
    }

    public function test_upcoming_shifts_visible_ordered_and_excludes_unpublished(): void
    {
        $employee = User::factory()->employee()->create();
        $employee->branches()->sync([$this->vancouver->id]);
        $tz = BranchTime::timezoneFor($this->vancouver);

        $nowLocal = Carbon::parse('2026-11-02 10:00:00', $tz);
        Carbon::setTestNow($nowLocal->copy()->utc());

        $publishedWeek = ScheduleWeek::factory()->for($this->vancouver)->published()->create([
            'week_start' => '2026-11-02',
        ]);
        $draftWeek = ScheduleWeek::factory()->for($this->vancouver)->create([
            'week_start' => '2026-11-09',
            'is_published' => false,
        ]);

        // Shift 1: Tomorrow 09:00 - 17:00 (published)
        $shift1 = Shift::create([
            'schedule_week_id' => $publishedWeek->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-03',
            'starts_at' => Carbon::parse('2026-11-03 09:00:00', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-11-03 17:00:00', $tz)->utc(),
        ]);

        // Shift 2: Day after tomorrow 10:00 - 18:00 (published)
        $shift2 = Shift::create([
            'schedule_week_id' => $publishedWeek->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-04',
            'starts_at' => Carbon::parse('2026-11-04 10:00:00', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-11-04 18:00:00', $tz)->utc(),
        ]);

        // Shift 3: Next week in draft week (unpublished)
        $unpublishedShift = Shift::create([
            'schedule_week_id' => $draftWeek->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-11-10',
            'starts_at' => Carbon::parse('2026-11-10 09:00:00', $tz)->utc(),
            'ends_at' => Carbon::parse('2026-11-10 17:00:00', $tz)->utc(),
        ]);

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        $response->assertViewHas('upcomingShifts', function ($shifts) use ($shift1, $shift2, $unpublishedShift) {
            // Must contain shift1 and shift2 in chronological order
            $ids = $shifts->pluck('id')->all();
            return $ids === [$shift1->id, $shift2->id]
                && ! $shifts->contains('id', $unpublishedShift->id);
        });

        Carbon::setTestNow();
    }

    public function test_multiple_branches_and_isolation_from_unrelated_employees(): void
    {
        $employee = User::factory()->employee()->create();
        $otherEmployee = User::factory()->employee()->create();

        $employee->branches()->sync([$this->richmond->id, $this->vancouver->id]);
        $otherEmployee->branches()->sync([$this->vancouver->id]);

        $tz = BranchTime::timezoneFor($this->vancouver);
        $today = '2026-11-02';

        $week = ScheduleWeek::factory()->for($this->vancouver)->published()->create([
            'week_start' => '2026-11-02',
        ]);

        // Shift for employee at Vancouver
        $empShift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $employee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 14:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 22:00:00", $tz)->utc(),
        ]);

        // Shift for OTHER employee at Vancouver
        $otherShift = Shift::create([
            'schedule_week_id' => $week->id,
            'user_id' => $otherEmployee->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => $today,
            'starts_at' => Carbon::parse("{$today} 08:00:00", $tz)->utc(),
            'ends_at' => Carbon::parse("{$today} 16:00:00", $tz)->utc(),
        ]);

        // Freeze time to 13:30 (within 60m of employee's shift)
        Carbon::setTestNow(Carbon::parse("{$today} 13:30:00", $tz)->utc());

        $response = $this->actingAs($employee)->get(route('employee.dashboard'));
        $response->assertOk();

        // todayShift must belong to employee and not other employee
        $response->assertViewHas('todayShift', fn ($shift) => $shift !== null && $shift->id === $empShift->id && $shift->user_id === $employee->id);

        // Vancouver must be preselected
        $response->assertSee('<option value="'.$this->vancouver->id.'" selected>', false);

        // upcomingShifts must only contain employee's shift
        $response->assertViewHas('upcomingShifts', function ($shifts) use ($empShift, $otherShift) {
            return $shifts->contains('id', $empShift->id)
                && ! $shifts->contains('id', $otherShift->id);
        });

        Carbon::setTestNow();
    }
}

