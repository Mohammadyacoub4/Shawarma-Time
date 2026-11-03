<?php

namespace Tests\Feature;

use App\Enums\TimesheetStatus;
use App\Exports\TimesheetReportExport;
use App\Models\Branch;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\ReportService;
use App\Services\WorkPeriodCalculator;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ReportServiceAndExportTest extends TestCase
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

    public function test_total_working_minutes_equals_approved_combined_minutes_and_excludes_pending_rejected_clockedin(): void
    {
        // 1. Approved entry: 240 mins (04:00)
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 16:00:00',
            'ends_at' => '2026-05-10 20:00:00',
            'total_minutes' => 240,
            'status' => TimesheetStatus::Approved,
        ]);

        // 2. Pending entry: 180 mins (03:00)
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 21:00:00',
            'ends_at' => '2026-05-11 00:00:00',
            'total_minutes' => 180,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // 3. Rejected entry: 120 mins (02:00)
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-11',
            'starts_at' => '2026-05-11 16:00:00',
            'ends_at' => '2026-05-11 18:00:00',
            'total_minutes' => 120,
            'status' => TimesheetStatus::Rejected,
        ]);

        // 4. Clocked in active entry: 0 mins
        TimesheetEntry::create([
            'user_id' => $this->employee2->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-11',
            'starts_at' => '2026-05-11 19:00:00',
            'ends_at' => null,
            'total_minutes' => 0,
            'status' => TimesheetStatus::ClockedIn,
        ]);

        $report = $this->reportService->build([
            'from' => '2026-05-10',
            'to' => '2026-05-11',
        ]);

        // total_working_minutes must represent ONLY approved working minutes
        $this->assertEquals(240, $report['approved_combined_minutes']);
        $this->assertEquals(240, $report['total_working_minutes']);
        $this->assertEquals($report['approved_combined_minutes'], $report['total_working_minutes']);

        // Pending and rejected must be kept separate
        $this->assertEquals(180, $report['pending_minutes']);
        $this->assertEquals(120, $report['rejected_minutes']);
        $this->assertEquals(1, $report['missing_checkout_count']);
        $this->assertEquals(4, $report['attendance_count']);
    }

    public function test_multiple_approved_shifts_per_day_aggregated_accurately(): void
    {
        // Morning shift: 3h 15m = 195 mins
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 15:00:00',
            'ends_at' => '2026-05-10 18:15:00',
            'total_minutes' => 195,
            'status' => TimesheetStatus::Approved,
        ]);

        // Evening shift: 4h 45m = 285 mins
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 19:00:00',
            'ends_at' => '2026-05-10 23:45:00',
            'total_minutes' => 285,
            'status' => TimesheetStatus::Approved,
        ]);

        $report = $this->reportService->build([
            'from' => '2026-05-10',
            'to' => '2026-05-10',
            'user_id' => $this->employee1->id,
        ]);

        // Total: 195 + 285 = 480 mins = exactly 8 hours
        $this->assertEquals(480, $report['approved_combined_minutes']);
        $this->assertEquals(480, $report['total_working_minutes']);
        $this->assertEquals('8:00', $this->calculator->formatHours($report['approved_combined_minutes']));
    }

    public function test_branch_and_date_filtering_isolate_approved_hours(): void
    {
        // Shift at Vancouver: 240 mins
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 16:00:00',
            'ends_at' => '2026-05-10 20:00:00',
            'total_minutes' => 240,
            'status' => TimesheetStatus::Approved,
        ]);

        // Shift at Richmond: 300 mins
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->richmond->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 21:00:00',
            'ends_at' => '2026-05-11 02:00:00',
            'total_minutes' => 300,
            'status' => TimesheetStatus::Approved,
        ]);

        // Filter Vancouver only
        $vancouverReport = $this->reportService->build([
            'branch_id' => $this->vancouver->id,
            'from' => '2026-05-10',
            'to' => '2026-05-10',
        ]);

        $this->assertEquals(240, $vancouverReport['approved_combined_minutes']);
        $this->assertEquals(240, $vancouverReport['total_working_minutes']);

        // Filter Richmond only
        $richmondReport = $this->reportService->build([
            'branch_id' => $this->richmond->id,
            'from' => '2026-05-10',
            'to' => '2026-05-10',
        ]);

        $this->assertEquals(300, $richmondReport['approved_combined_minutes']);
        $this->assertEquals(300, $richmondReport['total_working_minutes']);
    }

    public function test_export_preserves_all_rows_and_calculates_approved_hours_and_summary_totals(): void
    {
        // 1. Approved entry: 240 mins (4:00)
        $approvedEntry = TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 16:00:00',
            'ends_at' => '2026-05-10 20:00:00',
            'total_minutes' => 240,
            'status' => TimesheetStatus::Approved,
        ]);

        // 2. Pending entry: 180 mins (3:00)
        $pendingEntry = TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-10',
            'starts_at' => '2026-05-10 21:00:00',
            'ends_at' => '2026-05-11 00:00:00',
            'total_minutes' => 180,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // 3. Rejected entry: 120 mins (2:00)
        $rejectedEntry = TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-11',
            'starts_at' => '2026-05-11 16:00:00',
            'ends_at' => '2026-05-11 18:00:00',
            'total_minutes' => 120,
            'status' => TimesheetStatus::Rejected,
        ]);

        // 4. Active clock in: 0 mins
        $clockInEntry = TimesheetEntry::create([
            'user_id' => $this->employee2->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => '2026-05-11',
            'starts_at' => '2026-05-11 19:00:00',
            'ends_at' => null,
            'total_minutes' => 0,
            'status' => TimesheetStatus::ClockedIn,
        ]);

        $report = $this->reportService->build([
            'from' => '2026-05-10',
            'to' => '2026-05-11',
        ]);

        $export = new TimesheetReportExport($report, $this->calculator);

        $headings = $export->headings();
        $this->assertEquals([
            'Employee',
            'Branch',
            'Work Date',
            'Start Time',
            'End Time',
            'Recorded Duration',
            'Approved Hours',
            'Status',
            'Late?',
            'Late Minutes',
            'Submitted At',
        ], $headings);

        $dataRows = $export->array();

        // 4 timesheet rows + 1 blank row + 1 summary header + 3 summary rows = 9 rows total
        $this->assertCount(9, $dataRows);

        // Both entries are on 2026-05-11 so find by status in dataRows
        $rowApproved = collect($dataRows)->firstWhere(7, 'Approved');
        $rowPending = collect($dataRows)->firstWhere(7, 'Pending Approval');
        $rowRejected = collect($dataRows)->firstWhere(7, 'Rejected');
        $rowClockedIn = collect($dataRows)->firstWhere(7, 'Clocked In');

        $this->assertNotNull($rowApproved);
        $this->assertEquals($this->employee1->name, $rowApproved[0]);
        $this->assertEquals('2026-05-10 09:00:00', $rowApproved[3]); // Starts at converted to Etc/GMT+7
        $this->assertEquals('2026-05-10 13:00:00', $rowApproved[4]); // Ends at converted to Etc/GMT+7
        $this->assertEquals('4:00', $rowApproved[5]); // Recorded Duration
        $this->assertEquals('4:00', $rowApproved[6]); // Approved Hours

        $this->assertNotNull($rowPending);
        $this->assertEquals('3:00', $rowPending[5]); // Recorded Duration
        $this->assertEquals('0:00', $rowPending[6]); // Approved Hours must be 0:00

        $this->assertNotNull($rowRejected);
        $this->assertEquals('2:00', $rowRejected[5]); // Recorded Duration
        $this->assertEquals('0:00', $rowRejected[6]); // Approved Hours must be 0:00

        $this->assertNotNull($rowClockedIn);
        $this->assertEquals('0:00', $rowClockedIn[5]); // Recorded Duration
        $this->assertEquals('0:00', $rowClockedIn[6]); // Approved Hours must be 0:00

        // Summary header and values
        $this->assertEquals('SUMMARY TOTALS', $dataRows[5][0]);

        $this->assertEquals('Total Approved Hours', $dataRows[6][0]);
        $this->assertEquals('4:00', $dataRows[6][1]);

        $this->assertEquals('Total Pending Hours', $dataRows[7][0]);
        $this->assertEquals('3:00', $dataRows[7][1]);

        $this->assertEquals('Total Rejected Hours', $dataRows[8][0]);
        $this->assertEquals('2:00', $dataRows[8][1]);
    }

    public function test_admin_export_endpoint_returns_consistent_csv_and_xlsx_matching_report(): void
    {
        // Seed 1 Approved (2h) and 1 Pending (1h)
        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->subHours(5),
            'ends_at' => now()->subHours(3),
            'total_minutes' => 120,
            'status' => TimesheetStatus::Approved,
        ]);

        TimesheetEntry::create([
            'user_id' => $this->employee1->id,
            'branch_id' => $this->vancouver->id,
            'work_date' => now()->toDateString(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHours(1),
            'total_minutes' => 60,
            'status' => TimesheetStatus::PendingApproval,
        ]);

        // CSV download request
        $csvResponse = $this->actingAs($this->admin)->get(route('admin.reports.export', [
            'format' => 'csv',
        ]));
        $csvResponse->assertOk();
        $this->assertTrue(str_contains($csvResponse->headers->get('content-disposition'), '.csv'));

        // XLSX download request
        $xlsxResponse = $this->actingAs($this->admin)->get(route('admin.reports.export', [
            'format' => 'xlsx',
        ]));
        $xlsxResponse->assertOk();
        $this->assertTrue(str_contains($xlsxResponse->headers->get('content-disposition'), '.xlsx'));
    }
}
