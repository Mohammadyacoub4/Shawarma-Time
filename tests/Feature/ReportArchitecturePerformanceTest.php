<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Exports\TimesheetReportExport;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ReportService;
use App\Services\WorkPeriodCalculator;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportArchitecturePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee1;
    private User $employee2;
    private Branch $vancouver;
    private Branch $richmond;
    private ReportService $reportService;
    private WorkPeriodCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->vancouver = Branch::where('slug', 'vancouver')->firstOrFail();
        $this->richmond = Branch::where('slug', 'richmond')->firstOrFail();

        $this->admin = User::factory()->admin()->create();

        $this->employee1 = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->employee1->branches()->sync([$this->vancouver->id, $this->richmond->id]);

        $this->employee2 = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);
        $this->employee2->branches()->sync([$this->vancouver->id]);

        $this->reportService = app(ReportService::class);
        $this->calculator = app(WorkPeriodCalculator::class);
    }

    public function test_sql_report_aggregates_across_all_statuses_and_flags(): void
    {
        // 1. Approved Vancouver
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-06-01',
            'starts_at' => '2026-06-01 09:00:00',
            'ends_at' => '2026-06-01 17:00:00',
            'total_minutes' => 480,
            'status' => TimesheetStatus::Approved,
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        // 2. Pending Approval Richmond with Late Arrival
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->richmond->id,
            'work_date' => '2026-06-02',
            'starts_at' => '2026-06-02 09:30:00',
            'ends_at' => '2026-06-02 17:00:00',
            'total_minutes' => 450,
            'status' => TimesheetStatus::PendingApproval,
            'is_late' => true,
            'late_minutes' => 30,
        ]);

        // 3. Rejected Vancouver
        TimesheetEntry::create([
            'user_id' => $this->employee2->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-06-03',
            'starts_at' => '2026-06-03 10:00:00',
            'ends_at' => '2026-06-03 14:00:00',
            'total_minutes' => 240,
            'status' => TimesheetStatus::Rejected,
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        // 4. Missing Checkout (Clocked In Active)
        TimesheetEntry::create([
            'user_id' => $this->employee2->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-06-04',
            'starts_at' => '2026-06-04 08:00:00',
            'ends_at' => null,
            'total_minutes' => 0,
            'status' => TimesheetStatus::ClockedIn,
            'is_late' => false,
            'late_minutes' => 0,
        ]);

        // Scheduled shift at Vancouver
        Shift::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-06-01',
            'starts_at' => '2026-06-01 09:00:00',
            'ends_at' => '2026-06-01 17:00:00',
        ]);

        $report = $this->reportService->build([
            'from' => '2026-06-01',
            'to' => '2026-06-04',
        ]);

        // Verify summary metrics calculated via SQL
        $this->assertEquals(480, $report['approved_combined_minutes']);
        $this->assertEquals(480, $report['total_working_minutes']);
        $this->assertEquals(450, $report['pending_minutes']);
        $this->assertEquals(240, $report['rejected_minutes']);
        $this->assertEquals(4, $report['attendance_count']);
        $this->assertEquals(1, $report['late_count']);
        $this->assertEquals(30, $report['late_minutes']);
        $this->assertEquals(1, $report['missing_checkout_count']);
        $this->assertEquals(480, $report['scheduled_minutes']);

        // Verify branch breakdown
        $this->assertEquals(480, $report['by_branch'][$this->vancouver->id]['approved_minutes']);
        $this->assertEquals(0, $report['by_branch'][$this->vancouver->id]['pending_minutes']);
        $this->assertEquals(240, $report['by_branch'][$this->vancouver->id]['rejected_minutes']);
        $this->assertEquals(480, $report['by_branch'][$this->vancouver->id]['scheduled_minutes']);

        $this->assertEquals(0, $report['by_branch'][$this->richmond->id]['approved_minutes']);
        $this->assertEquals(450, $report['by_branch'][$this->richmond->id]['pending_minutes']);
        $this->assertEquals(0, $report['by_branch'][$this->richmond->id]['rejected_minutes']);
        $this->assertEquals(0, $report['by_branch'][$this->richmond->id]['scheduled_minutes']);

        // Verify that entries collection is NOT loaded in build() by default (preventing duplicate hydration)
        $this->assertArrayNotHasKey('entries', $report);
    }

    public function test_report_controller_index_paginates_and_retains_filters_without_full_entries_collection_in_summary(): void
    {
        // Seed 35 entries across 2 dates
        for ($i = 0; $i < 35; $i++) {
            TimesheetEntry::create([
                'user_id' => $this->employee1->id,
                'branch_id' => $this->vancouver->id,
                'work_date' => '2026-06-10',
                'starts_at' => '2026-06-10 09:00:00',
                'ends_at' => '2026-06-10 17:00:00',
                'total_minutes' => 480,
                'status' => TimesheetStatus::Approved,
            ]);
        }

        $response = $this->actingAs($this->admin)->get(route('admin.reports.index', [
            'from' => '2026-06-10',
            'to' => '2026-06-10',
            'branch_id' => $this->vancouver->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('paginatedEntries');
        $response->assertViewHas('report');

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginated */
        $paginated = $response->viewData('paginatedEntries');
        $this->assertEquals(20, $paginated->perPage());
        $this->assertEquals(35, $paginated->total());
        $this->assertCount(20, $paginated->items());

        $reportSummary = $response->viewData('report');
        $this->assertEquals(35 * 480, $reportSummary['approved_combined_minutes']);
        $this->assertEquals(35, $reportSummary['attendance_count']);
        // Crucial test for PERF-04: report does NOT duplicate the 35 entries in memory
        $this->assertArrayNotHasKey('entries', $reportSummary);
    }

    public function test_streaming_export_generator_produces_identical_rows_and_summaries_to_array(): void
    {
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-06-15',
            'starts_at' => '2026-06-15 09:00:00',
            'ends_at' => '2026-06-15 17:00:00',
            'total_minutes' => 480,
            'status' => TimesheetStatus::Approved,
        ]);

        TimesheetEntry::create([
            'user_id' => $this->employee2->id,
            'branch_id' => $this->richmond->id,
            'work_date' => '2026-06-15',
            'starts_at' => '2026-06-15 10:00:00',
            'ends_at' => '2026-06-15 18:00:00',
            'total_minutes' => 480,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        $filters = ['from' => '2026-06-15', 'to' => '2026-06-15'];
        $report = $this->reportService->build($filters);
        $query = $this->reportService->filteredEntriesQuery($filters);

        $export = new TimesheetReportExport($report, $query, $this->calculator);

        $rows = $export->array();

        // 2 data rows + 1 blank row + 1 header row + 3 summary rows = 7 rows
        $this->assertCount(7, $rows);

        // Check headings
        $this->assertEquals('Employee', $export->headings()[0]);
        $this->assertEquals('Submitted At', $export->headings()[10]);

        // Check data row contents
        $this->assertEquals($this->employee1->name, $rows[0][0]);
        $this->assertEquals($this->vancouver->name, $rows[0][1]);
        $this->assertEquals('8:00', $rows[0][5]); // Recorded duration
        $this->assertEquals('8:00', $rows[0][6]); // Approved hours
        $this->assertEquals('Approved', $rows[0][7]);

        $this->assertEquals($this->employee2->name, $rows[1][0]);
        $this->assertEquals($this->richmond->name, $rows[1][1]);
        $this->assertEquals('8:00', $rows[1][5]);
        $this->assertEquals('0:00', $rows[1][6]); // Pending entry approved hours must be 0:00
        $this->assertEquals('Pending Approval', $rows[1][7]);

        // Check summary row labels and values
        $this->assertEquals('SUMMARY TOTALS', $rows[3][0]);
        $this->assertEquals('Total Approved Hours', $rows[4][0]);
        $this->assertEquals('8:00', $rows[4][1]);
        $this->assertEquals('Total Pending Hours', $rows[5][0]);
        $this->assertEquals('8:00', $rows[5][1]);
        $this->assertEquals('Total Rejected Hours', $rows[6][0]);
        $this->assertEquals('0:00', $rows[6][1]);
    }

    public function test_csv_and_xlsx_downloads_execute_successfully_with_query_streaming(): void
    {
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-06-20',
            'starts_at' => '2026-06-20 09:00:00',
            'ends_at' => '2026-06-20 17:00:00',
            'total_minutes' => 480,
            'status' => TimesheetStatus::Approved,
        ]);

        $csv = $this->actingAs($this->admin)->get(route('admin.reports.export', [
            'format' => 'csv',
            'from' => '2026-06-20',
            'to' => '2026-06-20',
        ]));
        $csv->assertOk();
        $this->assertTrue(str_contains($csv->headers->get('content-disposition'), '.csv'));

        $xlsx = $this->actingAs($this->admin)->get(route('admin.reports.export', [
            'format' => 'xlsx',
            'from' => '2026-06-20',
            'to' => '2026-06-20',
        ]));
        $xlsx->assertOk();
        $this->assertTrue(str_contains($xlsx->headers->get('content-disposition'), '.xlsx'));
    }

    public function test_export_eager_loading_avoids_n_plus_one_relationship_queries(): void
    {
        // Seed 25 entries across different dates/employees
        for ($i = 0; $i < 25; $i++) {
            TimesheetEntry::create([
                'user_id' => $i % 2 === 0 ? $this->employee1->id : $this->employee2->id,
                'branch_id' => $i % 2 === 0 ? $this->vancouver->id : $this->richmond->id,
                'work_date' => '2026-07-01',
                'starts_at' => '2026-07-01 09:00:00',
                'ends_at' => '2026-07-01 17:00:00',
                'total_minutes' => 480,
                'status' => TimesheetStatus::Approved,
            ]);
        }

        $filters = ['from' => '2026-07-01', 'to' => '2026-07-01'];
        $report = $this->reportService->build($filters);
        $query = $this->reportService->filteredEntriesQuery($filters);

        $export = new TimesheetReportExport($report, $query, $this->calculator);

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $rowsCount = 0;
        foreach ($export->generator() as $row) {
            $rowsCount++;
        }

        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // 25 timesheet data rows + 1 blank + 1 header + 3 summaries = 30 rows generated
        $this->assertEquals(30, $rowsCount);

        // Previous N+1 implementation issued 1 main query + 2 queries per row (user & branch) = 51 queries.
        // With lazy chunking and proper eager loading, 25 records fit into a single 500-item chunk:
        // Query 1: SELECT * FROM timesheet_entries ...
        // Query 2: SELECT * FROM users WHERE id IN (...)
        // Query 3: SELECT * FROM branches WHERE id IN (...)
        // Total relationship queries during generator iteration should be exactly 3, not 51.
        $this->assertLessThanOrEqual(5, count($queries), 'Export generator must eager load relationships in chunks rather than issuing N+1 queries per row.');
    }
}
