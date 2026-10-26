<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TimesheetReportExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\ReportService;
use App\Services\WorkPeriodCalculator;
use App\Support\BranchTime;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports, WorkPeriodCalculator $calculator): View
    {
        $filters = $this->filtersFromRequest($request);
        $report = $reports->build($filters);
        $paginatedEntries = $reports->filteredEntriesQuery($filters)
            ->with(['user', 'branch'])
            ->orderByDesc('work_date')
            ->orderByDesc('starts_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.reports.index', [
            'report' => $report,
            'paginatedEntries' => $paginatedEntries,
            'filters' => $filters,
            'branches' => Branch::orderBy('name')->get(),
            'employees' => User::where('role', 'employee')->orderBy('name')->get(),
            'calculator' => $calculator,
        ]);
    }

    public function export(Request $request, ReportService $reports): BinaryFileResponse
    {
        $filters = $this->filtersFromRequest($request);
        $report = $reports->build($filters);
        $format = strtolower($request->input('format', 'xlsx'));
        $ext = $format === 'csv' ? 'csv' : 'xlsx';
        $writerType = $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX;
        $filename = 'shawarma-time-report-'.now()->format('Y-m-d-His').'.'.$ext;

        return Excel::download(new TimesheetReportExport($report), $filename, $writerType);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request): array
    {
        $weekStart = BranchTime::weekStart($request->input('week', now(BranchTime::DEFAULT_TIMEZONE)))->toDateString();
        $weekEnd = BranchTime::weekEnd(BranchTime::weekStart($weekStart))->toDateString();

        return [
            'branch_id' => $request->input('branch_id'),
            'user_id' => $request->input('user_id'),
            'status' => $request->input('status'),
            'late_only' => $request->boolean('late_only'),
            'missing_checkout' => $request->boolean('missing_checkout'),
            'from' => $request->input('from', $weekStart),
            'to' => $request->input('to', $weekEnd),
        ];
    }
}
