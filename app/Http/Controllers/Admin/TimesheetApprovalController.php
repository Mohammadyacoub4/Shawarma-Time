<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TimesheetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUpdateTimesheetRequest;
use App\Http\Requests\RejectTimesheetRequest;
use App\Models\Branch;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimesheetApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $query = TimesheetEntry::with(['user', 'branch'])->latest('submitted_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        } else {
            $query->where('status', TimesheetStatus::PendingApproval);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return view('admin.timesheets.index', [
            'entries' => $query->paginate(25)->withQueryString(),
            'branches' => Branch::orderBy('name')->get(),
            'employees' => User::where('role', 'employee')->orderBy('name')->get(),
        ]);
    }

    public function show(TimesheetEntry $timesheet): View
    {
        $this->authorize('view', $timesheet);

        return view('admin.timesheets.show', [
            'entry' => $timesheet->load(['user', 'branch', 'shift', 'auditLogs.performer']),
        ]);
    }

    public function update(AdminUpdateTimesheetRequest $request, TimesheetEntry $timesheet, TimesheetService $service): RedirectResponse
    {
        $this->authorize('update', $timesheet);

        try {
            $service->adminUpdate($timesheet, $request->user(), $request->validated());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['timesheet' => $e->getMessage()]);
        }

        return back()->with('status', 'Timesheet updated.');
    }

    public function approve(TimesheetEntry $timesheet, TimesheetService $service): RedirectResponse
    {
        $this->authorize('approve', $timesheet);

        try {
            $service->approve($timesheet, auth()->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['timesheet' => $e->getMessage()]);
        }

        return back()->with('status', 'Timesheet approved.');
    }

    public function reject(RejectTimesheetRequest $request, TimesheetEntry $timesheet, TimesheetService $service): RedirectResponse
    {
        $this->authorize('reject', $timesheet);

        try {
            $service->reject($timesheet, $request->user(), $request->string('rejection_reason')->toString());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['timesheet' => $e->getMessage()]);
        }

        return back()->with('status', 'Timesheet rejected.');
    }
}
