<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user() ?? auth()->user();
        $userBranches = $user?->isAdmin()
            ? Branch::orderBy('name')->get()
            : ($user?->branches()->orderBy('name')->get() ?? collect());

        $branchId = $request->integer('branch_id');
        $branch = $branchId > 0
            ? $userBranches->firstWhere('id', $branchId)
            : $userBranches->first();

        $branches = $userBranches;

        if (! $branch) {
            $fallbackWeekStart = BranchTime::weekStart(null, null);
            return view('employee.schedules.index', [
                'branches' => collect(),
                'branch' => null,
                'weekStart' => $fallbackWeekStart->toDateString(),
                'weekEnd' => BranchTime::weekEnd($fallbackWeekStart)->toDateString(),
                'prevWeek' => $fallbackWeekStart->copy()->subDays(7)->toDateString(),
                'nextWeek' => $fallbackWeekStart->copy()->addDays(7)->toDateString(),
                'isCurrentWeek' => true,
                'days' => collect(),
                'shiftsByDay' => collect(),
                'myShiftCount' => 0,
                'scheduleWeek' => null,
            ]);
        }

        $tz = BranchTime::timezoneFor($branch);
        $weekParam = $request->query('week');
        $parsedWeek = now($tz);
        if (is_string($weekParam) && trim($weekParam) !== '') {
            try {
                $parsedWeek = Carbon::parse($weekParam, $tz);
            } catch (\Exception) {
                $parsedWeek = now($tz);
            }
        }
        $weekStart = BranchTime::weekStart($parsedWeek, $branch)->toDateString();
        $weekEnd = BranchTime::weekEnd(Carbon::parse($weekStart, $tz))->toDateString();

        $currentWeekStart = BranchTime::weekStart(now($tz), $branch)->toDateString();
        $isCurrentWeek = $weekStart === $currentWeekStart;
        $prevWeek = Carbon::parse($weekStart, $tz)->subDays(7)->toDateString();
        $nextWeek = Carbon::parse($weekStart, $tz)->addDays(7)->toDateString();

        $scheduleWeek = ScheduleWeek::where('branch_id', $branch->id)
            ->where(function ($q) use ($weekStart) {
                $q->whereDate('week_start', $weekStart)
                  ->orWhere('week_start', 'like', $weekStart.'%');
            })
            ->first();

        $isPublished = (bool) $scheduleWeek?->is_published;

        $shifts = $isPublished
            ? Shift::with('user')
                ->where('branch_id', $branch->id)
                ->whereBetween('work_date', [$weekStart, $weekEnd])
                ->orderBy('work_date')
                ->orderBy('starts_at')
                ->get()
                ->groupBy(fn (Shift $s) => $s->work_date->toDateString())
            : collect();

        $days = collect(range(0, 6))->map(fn (int $offset) => Carbon::parse($weekStart, $tz)->addDays($offset)->toDateString());

        $myShifts = $shifts->flatten()->where('user_id', $user->id);

        return view('employee.schedules.index', [
            'branches' => $branches,
            'branch' => $branch,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'prevWeek' => $prevWeek,
            'nextWeek' => $nextWeek,
            'isCurrentWeek' => $isCurrentWeek,
            'days' => $days,
            'shiftsByDay' => $shifts,
            'myShiftCount' => $myShifts->count(),
            'scheduleWeek' => $scheduleWeek,
        ]);
    }
}
