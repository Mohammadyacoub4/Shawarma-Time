<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShiftRequest;
use App\Models\Branch;
use App\Models\ScheduleWeek;
use App\Models\Shift;
use App\Models\User;
use App\Services\ScheduleService;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ScheduleController extends Controller
{
    public function index(Request $request): View
    {
        $branchId = $request->integer('branch_id');
        $branch = $branchId > 0 ? Branch::find($branchId) : null;
        $branch = $branch ?? Branch::orderBy('id')->first();

        $branches = Branch::orderBy('name')->get();

        if (! $branch) {
            return view('admin.schedules.index', [
                'branches' => $branches,
                'branch' => null,
                'weekStart' => now()->startOfWeek()->toDateString(),
                'weekEnd' => now()->endOfWeek()->toDateString(),
                'prevWeek' => now()->subDays(7)->startOfWeek()->toDateString(),
                'nextWeek' => now()->addDays(7)->startOfWeek()->toDateString(),
                'isCurrentWeek' => true,
                'days' => collect(),
                'shiftsByDay' => collect(),
                'scheduleWeek' => null,
                'employees' => collect(),
                'totalShifts' => 0,
                'totalScheduledMinutes' => 0,
            ]);
        }

        $tz = BranchTime::timezoneFor($branch);
        $weekParam = $request->input('week');
        $weekStart = BranchTime::weekStart($weekParam ? Carbon::parse($weekParam, $tz) : now($tz), $branch)->toDateString();
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

        $shiftsCollection = Shift::with('user')
            ->where('branch_id', $branch->id)
            ->whereBetween('work_date', [$weekStart, $weekEnd])
            ->orderBy('work_date')
            ->orderBy('starts_at')
            ->get();

        $shiftsByDay = $shiftsCollection->groupBy(fn (Shift $s) => $s->work_date->toDateString());

        $days = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $tz) {
            return Carbon::parse($weekStart, $tz)->addDays($offset)->toDateString();
        });

        $employees = User::where('role', UserRole::Employee)->where('is_active', true)
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id))
            ->orderBy('name')
            ->get();

        $totalShifts = $shiftsCollection->count();
        $totalScheduledMinutes = $shiftsCollection->sum(function (Shift $s) {
            return $s->starts_at && $s->ends_at ? $s->starts_at->diffInMinutes($s->ends_at) : 0;
        });

        return view('admin.schedules.index', [
            'branches' => $branches,
            'branch' => $branch,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'prevWeek' => $prevWeek,
            'nextWeek' => $nextWeek,
            'isCurrentWeek' => $isCurrentWeek,
            'days' => $days,
            'shiftsByDay' => $shiftsByDay,
            'scheduleWeek' => $scheduleWeek,
            'employees' => $employees,
            'totalShifts' => $totalShifts,
            'totalScheduledMinutes' => $totalScheduledMinutes,
        ]);
    }

    public function store(StoreShiftRequest $request, ScheduleService $scheduleService): RedirectResponse
    {
        try {
            $scheduleService->upsertShift($request->validated());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['shift' => $e->getMessage()]);
        }

        return back()->with('status', 'تم حفظ المناوبة بنجاح / Shift saved successfully.');
    }

    public function batchStore(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['required', 'date'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $shifts = $scheduleService->batchUpsertShifts($validated);
            $count = count($shifts);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['shift' => $e->getMessage()]);
        }

        return back()->with('status', "تمت جدولة المناوبات بنجاح لـ {$count} أيام / {$count} shifts scheduled across selected days.");
    }

    public function update(StoreShiftRequest $request, Shift $shift, ScheduleService $scheduleService): RedirectResponse
    {
        try {
            $scheduleService->upsertShift($request->validated(), $shift);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['shift' => $e->getMessage()]);
        }

        return back()->with('status', 'تم تعديل المناوبة بنجاح / Shift updated successfully.');
    }

    public function destroy(Shift $shift, ScheduleService $scheduleService): RedirectResponse
    {
        $scheduleService->deleteShift($shift);

        return back()->with('status', 'تم حذف المناوبة بنجاح / Shift deleted.');
    }

    public function publish(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $branch = Branch::findOrFail($request->integer('branch_id'));
        $weekStart = BranchTime::weekStart($request->input('week_start'), $branch)->toDateString();

        $scheduleService->publishWeek($branch, $weekStart, $request->user());

        return back()->with('status', 'تم اعتماد ونشر جدول هذا الأسبوع بنجاح! أصبح الجدول ظاهراً الآن لجميع الموظفين. / Schedule published to all employees.');
    }

    public function unpublish(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $branch = Branch::findOrFail($request->integer('branch_id'));
        $weekStart = BranchTime::weekStart($request->input('week_start'), $branch)->toDateString();

        $scheduleService->unpublishWeek($branch, $weekStart);

        return back()->with('status', 'تم إلغاء نشر الجدول وتحويله إلى مسودة غير ظاهرة للموظفين / Schedule returned to draft.');
    }

    public function copyPreviousWeek(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $branch = Branch::findOrFail($request->integer('branch_id'));
        $weekStart = BranchTime::weekStart($request->input('week_start'), $branch)->toDateString();

        try {
            $copied = $scheduleService->copyPreviousWeek($branch, $weekStart, $request->user());
            return back()->with('status', "تم نسخ {$copied} مناوبة بنجاح من الأسبوع السابق! / Successfully copied {$copied} shifts from last week.");
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['shift' => $e->getMessage()]);
        }
    }

    public function clearWeek(Request $request, ScheduleService $scheduleService): RedirectResponse
    {
        $branch = Branch::findOrFail($request->integer('branch_id'));
        $weekStart = BranchTime::weekStart($request->input('week_start'), $branch)->toDateString();

        $deleted = $scheduleService->clearWeek($branch, $weekStart);

        return back()->with('status', "تم مسح جميع مناوبات هذا الأسبوع ({$deleted} مناوبة) / Cleared {$deleted} shifts.");
    }
}
