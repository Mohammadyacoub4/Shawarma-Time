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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $today = now(BranchTime::DEFAULT_TIMEZONE)->toDateString();
        $dateParam = $request->query('date');
        $selectedDate = $today;
        if (is_string($dateParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
            try {
                $selectedDate = \Carbon\Carbon::parse($dateParam, BranchTime::DEFAULT_TIMEZONE)->toDateString();
            } catch (\Exception) {
                $selectedDate = $today;
            }
        }
        $weekStart = BranchTime::weekStart($selectedDate)->toDateString();
        $weekEnd = BranchTime::weekEnd(BranchTime::weekStart($selectedDate))->toDateString();

        $activeEmployees = User::where('role', UserRole::Employee)->where('is_active', true)->count();

        // 1. Employees currently clocked in
        $currentlyClockedIn = TimesheetEntry::with(['user', 'branch'])
            ->whereHas('user', fn ($q) => $q->where('role', UserRole::Employee))
            ->whereNull('ends_at')
            ->where('status', TimesheetStatus::ClockedIn)
            ->latest('starts_at')
            ->get();

        // 2. Attendance activity for the selected date
        $attendanceActivity = TimesheetEntry::with(['user', 'branch'])
            ->whereHas('user', fn ($q) => $q->where('role', UserRole::Employee))
            ->whereDate('work_date', $selectedDate)
            ->latest('starts_at')
            ->get();

        // 3. Late arrivals for selected date
        $lateArrivals = TimesheetEntry::with(['user', 'branch'])
            ->whereHas('user', fn ($q) => $q->where('role', UserRole::Employee))
            ->whereDate('work_date', $selectedDate)
            ->where('is_late', true)
            ->get();

        // 4. Absent employees for selected date based on assigned published schedules (per-shift detection)
        $nowUtcString = now()->utc()->format('Y-m-d H:i:s');
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        $absentShifts = Shift::with(['user', 'branch'])
            ->whereDate('work_date', $selectedDate)
            ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
            ->where(function (Builder $q) use ($selectedDate, $today, $nowUtcString) {
                if ($selectedDate < $today) {
                    return; // Past dates: all shifts have already passed start time
                }
                // Today: only shifts whose start time has arrived
                $q->where('starts_at', '<=', $nowUtcString);
            })
            // 1. Direct Link: must NOT have a matching timesheet entry with a valid status
            ->whereDoesntHave('timesheetEntries', function (Builder $q) {
                $q->whereColumn('timesheet_entries.user_id', 'shifts.user_id')
                  ->whereColumn('timesheet_entries.branch_id', 'shifts.branch_id')
                  ->whereColumn('timesheet_entries.work_date', 'shifts.work_date')
                  ->whereIn('timesheet_entries.status', [
                      TimesheetStatus::Approved,
                      TimesheetStatus::PendingApproval,
                      TimesheetStatus::ClockedIn,
                  ]);
            })
            // 2. Legacy Fallback: must NOT have an unlinked entry covering this shift
            ->whereNotExists(function (\Illuminate\Database\Query\Builder $sub) use ($isSqlite) {
                $earlyWindowSql = $isSqlite
                    ? "timesheet_entries.starts_at >= datetime(shifts.starts_at, '-60 minutes')"
                    : "timesheet_entries.starts_at >= DATE_SUB(shifts.starts_at, INTERVAL 60 MINUTE)";

                $sub->selectRaw(1)
                    ->from('timesheet_entries')
                    ->whereNull('timesheet_entries.shift_id')
                    ->whereColumn('timesheet_entries.user_id', 'shifts.user_id')
                    ->whereColumn('timesheet_entries.branch_id', 'shifts.branch_id')
                    ->whereColumn('timesheet_entries.work_date', 'shifts.work_date')
                    ->whereIn('timesheet_entries.status', [
                        TimesheetStatus::Approved->value,
                        TimesheetStatus::PendingApproval->value,
                        TimesheetStatus::ClockedIn->value,
                    ])
                    // Must have started before the shift ended
                    ->whereColumn('timesheet_entries.starts_at', '<', 'shifts.ends_at')
                    // Overlap condition for closed vs open entries
                    ->where(function ($overlapQ) use ($earlyWindowSql) {
                        $overlapQ->where(function ($closedQ) {
                            $closedQ->whereNotNull('timesheet_entries.ends_at')
                                    ->whereColumn('timesheet_entries.ends_at', '>', 'shifts.starts_at');
                        })->orWhere(function ($openQ) use ($earlyWindowSql) {
                            $openQ->whereNull('timesheet_entries.ends_at')
                                  ->whereRaw($earlyWindowSql);
                        });
                    })
                    // Must NOT have already satisfied an earlier published shift at the same branch
                    ->whereNotExists(function (\Illuminate\Database\Query\Builder $earlierSub) {
                        $earlierSub->selectRaw(1)
                            ->from('shifts as earlier_shifts')
                            ->join('schedule_weeks', 'schedule_weeks.id', '=', 'earlier_shifts.schedule_week_id')
                            ->whereColumn('earlier_shifts.user_id', 'shifts.user_id')
                            ->whereColumn('earlier_shifts.branch_id', 'shifts.branch_id')
                            ->whereColumn('earlier_shifts.work_date', 'shifts.work_date')
                            ->whereColumn('earlier_shifts.id', '!=', 'shifts.id')
                            ->whereColumn('earlier_shifts.starts_at', '<', 'shifts.starts_at')
                            ->where('schedule_weeks.is_published', true)
                            ->whereColumn('timesheet_entries.starts_at', '<', 'earlier_shifts.ends_at')
                            ->where(function ($q) {
                                $q->whereColumn('timesheet_entries.ends_at', '>', 'earlier_shifts.starts_at')
                                  ->orWhereNull('timesheet_entries.ends_at');
                            });
                    });
            })
            ->orderBy('starts_at')
            ->get();

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

        $approvedTotals = TimesheetEntry::where('status', TimesheetStatus::Approved)
            ->whereNotNull('ends_at')
            ->whereHas('user', fn ($q) => $q->where('role', UserRole::Employee))
            ->whereBetween('work_date', [$weekStart, $weekEnd])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(total_minutes) as total_minutes')
            ->pluck('total_minutes', 'branch_id');

        $approvedByBranch = $branches->mapWithKeys(function (Branch $branch) use ($approvedTotals) {
            return [$branch->id => (int) ($approvedTotals[$branch->id] ?? 0)];
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
