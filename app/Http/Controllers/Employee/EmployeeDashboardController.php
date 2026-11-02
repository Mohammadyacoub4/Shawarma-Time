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
        $userBranches = $user->branches()->orderBy('name')->get();
        $userBranch = $userBranches->first();
        $branchIds = $userBranches->pluck('id');
        $tz = BranchTime::timezoneFor($userBranch);
        $today = now($tz)->toDateString();

        $now = now();
        $nowUtcString = $now->copy()->utc()->format('Y-m-d H:i:s');
        $maxEarlyStartUtcString = $now->copy()->utc()->addMinutes(60)->format('Y-m-d H:i:s');

        $upcomingShifts = Shift::with(['branch', 'scheduleWeek'])
            ->where('user_id', $user->id)
            ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
            ->where('ends_at', '>=', $nowUtcString)
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        $publishedWeeks = $branchIds->isNotEmpty()
            ? ScheduleWeek::with('branch')
                ->where('is_published', true)
                ->whereIn('branch_id', $branchIds)
                ->latest('week_start')
                ->limit(6)
                ->get()
            : collect();

        $entries = TimesheetEntry::with('branch')
            ->where('user_id', $user->id)
            ->latest('submitted_at')
            ->limit(15)
            ->get();

        $activeClockIn = $user->activeClockIn();

        // Priority A: Active or near-start shift (within 60m of start, has not ended)
        $todayShift = Shift::with('branch')
            ->where('user_id', $user->id)
            ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
            ->where('starts_at', '<=', $maxEarlyStartUtcString)
            ->where('ends_at', '>=', $nowUtcString)
            ->orderBy('starts_at')
            ->first();

        // Priority B: Earliest remaining shift scheduled for current local date that has not ended
        if (! $todayShift) {
            $todayShift = Shift::with('branch')
                ->where('user_id', $user->id)
                ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
                ->whereDate('work_date', $today)
                ->where('ends_at', '>=', $nowUtcString)
                ->orderBy('starts_at')
                ->first();
        }

        return view('employee.dashboard', [
            'upcomingShifts' => $upcomingShifts,
            'todayShift' => $todayShift,
            'activeClockIn' => $activeClockIn,
            'userBranches' => $userBranches,
            'publishedWeeks' => $publishedWeeks,
            'entries' => $entries,
            'totals' => $reports->employeeHourTotals($user->id),
            'calculator' => $calculator,
            'tz' => $tz,
            'userBranch' => $userBranch,
            'statusCounts' => [
                'clocked_in' => $activeClockIn ? 1 : 0,
                'pending' => $entries->where('status', TimesheetStatus::PendingApproval)->count(),
                'approved' => TimesheetEntry::where('user_id', $user->id)->where('status', TimesheetStatus::Approved)->count(),
                'rejected' => TimesheetEntry::where('user_id', $user->id)->where('status', TimesheetStatus::Rejected)->count(),
            ],
        ]);
    }
}
