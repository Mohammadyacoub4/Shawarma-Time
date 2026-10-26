<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TimesheetStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = now(BranchTime::DEFAULT_TIMEZONE)->toDateString();
        $selectedDate = $request->input('date', $today);
        $weekStart = BranchTime::weekStart($selectedDate)->toDateString();
        $weekEnd = BranchTime::weekEnd(BranchTime::weekStart($selectedDate))->toDateString();

        $activeEmployees = User::where('role', UserRole::Employee)->where('is_active', true)->count();

        // 1. Employees currently clocked in
        $currentlyClockedIn = TimesheetEntry::with(['user', 'branch'])
            ->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('status', TimesheetStatus::ClockedIn);
            })
            ->latest('starts_at')
            ->get();

        // 2. Attendance activity for the selected date
        $attendanceActivity = TimesheetEntry::with(['user', 'branch'])
            ->whereDate('work_date', $selectedDate)
            ->latest('starts_at')
            ->get();

        // 3. Late arrivals for selected date
        $lateArrivals = TimesheetEntry::with(['user', 'branch'])
            ->whereDate('work_date', $selectedDate)
            ->where('is_late', true)
            ->get();

        // 4. Absent employees for selected date based on assigned published schedules
        $shiftsForDate = Shift::with(['user', 'branch'])
            ->whereDate('work_date', $selectedDate)
            ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
            ->get();

        $attendedUserIds = TimesheetEntry::whereDate('work_date', $selectedDate)
            ->pluck('user_id')
            ->toArray();

        $absentShifts = $shiftsForDate->filter(function (Shift $shift) use ($attendedUserIds, $selectedDate, $today) {
            $isPastStartTime = ($selectedDate < $today) || now()->greaterThan($shift->starts_at);
            return $isPastStartTime && ! in_array($shift->user_id, $attendedUserIds);
        });

        // 5. Recent check-in & check-out records (overall)
        $recentRecords = TimesheetEntry::with(['user', 'branch'])
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $pendingApprovals = TimesheetEntry::with(['user', 'branch'])
            ->where('status', TimesheetStatus::PendingApproval)
            ->latest('submitted_at')
            ->limit(10)
            ->get();

        $branches = Branch::withCount([
            'users as active_employees_count' => fn ($q) => $q->where('role', UserRole::Employee)->where('is_active', true),
        ])->orderBy('name')->get();

        $approvedByBranch = Branch::all()->mapWithKeys(function (Branch $branch) use ($weekStart, $weekEnd) {
            $minutes = TimesheetEntry::where('branch_id', $branch->id)
                ->where('status', TimesheetStatus::Approved)
                ->whereBetween('work_date', [$weekStart, $weekEnd])
                ->sum('total_minutes');

            return [$branch->id => (int) $minutes];
        })->toArray();

        return view('admin.dashboard', [
            'selectedDate' => $selectedDate,
            'today' => $today,
            'activeEmployees' => $activeEmployees,
            'currentlyClockedIn' => $currentlyClockedIn,
            'attendanceActivity' => $attendanceActivity,
            'lateArrivals' => $lateArrivals,
            'absentShifts' => $absentShifts,
            'recentRecords' => $recentRecords,
            'branches' => $branches,
            'shiftsToday' => Shift::with(['user', 'branch'])->whereDate('work_date', $selectedDate)->orderBy('starts_at')->get(),
            'weekShifts' => Shift::with(['user', 'branch'])->whereBetween('work_date', [$weekStart, $weekEnd])->count(),
            'pendingApprovals' => $pendingApprovals,
            'pendingCount' => TimesheetEntry::where('status', TimesheetStatus::PendingApproval)->count(),
            'approvedByBranch' => $approvedByBranch,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
        ]);
    }
}
