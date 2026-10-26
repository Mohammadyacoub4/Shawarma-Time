<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTimesheetRequest;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TimesheetEntry;
use App\Services\TimesheetService;
use App\Support\BranchTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TimesheetController extends Controller
{
    public function create(): View
    {
        $user = auth()->user();

        return view('employee.timesheets.create', [
            'branches' => $user->branches()->orderBy('name')->get(),
            'shifts' => Shift::with('branch')
                ->where('user_id', $user->id)
                ->whereHas('scheduleWeek', fn ($q) => $q->where('is_published', true))
                ->whereDate('work_date', '>=', now(BranchTime::DEFAULT_TIMEZONE)->subDays(14)->toDateString())
                ->orderByDesc('work_date')
                ->get(),
        ]);
    }

    public function store(StoreTimesheetRequest $request, TimesheetService $service): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isAssignedToBranch((int) $request->input('branch_id'))) {
            abort(403);
        }

        if ($request->filled('shift_id')) {
            $shift = Shift::findOrFail($request->integer('shift_id'));
            if ($shift->user_id !== $user->id) {
                abort(403);
            }
        }

        try {
            $service->submit($user, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['timesheet' => $e->getMessage()]);
        }

        return redirect()->route('employee.dashboard')->with('status', 'Timesheet submitted for approval.');
    }

    public function edit(TimesheetEntry $timesheet): View
    {
        $this->authorize('update', $timesheet);

        return view('employee.timesheets.edit', [
            'entry' => $timesheet->load('branch'),
            'branches' => auth()->user()->branches()->orderBy('name')->get(),
        ]);
    }

    public function update(StoreTimesheetRequest $request, TimesheetEntry $timesheet, TimesheetService $service): RedirectResponse
    {
        $this->authorize('update', $timesheet);

        if (! $request->user()->isAssignedToBranch((int) $request->input('branch_id'))) {
            abort(403);
        }

        try {
            $service->submit($request->user(), $request->validated(), $timesheet);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['timesheet' => $e->getMessage()]);
        }

        return redirect()->route('employee.dashboard')->with('status', 'Timesheet resubmitted for approval.');
    }
}
