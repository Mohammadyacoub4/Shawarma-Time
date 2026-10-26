<?php

namespace App\Http\Controllers\Employee;

use App\Enums\TimesheetStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Services\ReportService;
use App\Services\WorkPeriodCalculator;
use App\Support\BranchTime;
use Illuminate\View\View;

class EmployeeDashboardController extends Controller
{
    public function __invoke(ReportService $reports, WorkPeriodCalculator $calculator): View
    {
        $user = auth()->user();
        $userBranch = $user->branches()->first();
        $tz = BranchTime::timezoneFor($userBranch);
        $today = now($tz)->toDateString();

        $upcomingShifts = Shift::with(['branch', 'scheduleWeek'])
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $today)
            ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        $publishedWeeks = ScheduleWeek::with('branch')
            ->where('is_published', true)
            ->whereIn('branch_id', $user->branches()->exists() ? $user->branches()->pluck('branches.id') : Branch::pluck('id'))
            ->latest('week_start')
            ->limit(6)
            ->get();

        $entries = TimesheetEntry::with('branch')
            ->where('user_id', $user->id)
            ->latest('submitted_at')
            ->limit(15)
            ->get();

        $activeClockIn = $user->activeClockIn();

        $todayShift = Shift::with('branch')
            ->where('user_id', $user->id)
            ->whereDate('work_date', $today)
            ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
            ->first();

        $userBranches = $user->branches()->orderBy('name')->get();

        return view('employee.dashboard', [
            'upcomingShifts' => $upcomingShifts,
            'todayShift' => $todayShift,
            'activeClockIn' => $activeClockIn,
            'userBranches' => $userBranches,
            'publishedWeeks' => $publishedWeeks,
            'entries' => $entries,
            'totals' => $reports->employeeHourTotals($user->id),
            'calculator' => $calculator,
            'statusCounts' => [
                'clocked_in' => $activeClockIn ? 1 : 0,
                'pending' => $entries->where('status', TimesheetStatus::PendingApproval)->count(),
                'approved' => TimesheetEntry::where('user_id', $user->id)->where('status', TimesheetStatus::Approved)->count(),
                'rejected' => TimesheetEntry::where('user_id', $user->id)->where('status', TimesheetStatus::Rejected)->count(),
            ],
        ]);
    }
}
