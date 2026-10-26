<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Services\TimesheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AttendanceController extends Controller
{
    public function clockIn(Request $request, TimesheetService $service): RedirectResponse
    {
        $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        if (! $user->isAssignedToBranch($request->integer('branch_id'))) {
            $msg = 'أنت غير مسجل في هذا الفرع / You are not assigned to this branch.';
            return back()->with('error', $msg)->withErrors(['attendance' => $msg]);
        }

        try {
            $service->clockIn(
                employee: $user,
                branchId: $request->integer('branch_id'),
                notes: $request->input('notes'),
                ip: $request->ip(),
                deviceInfo: $request->userAgent()
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withErrors(['attendance' => $e->getMessage()]);
        }

        return redirect()->route('employee.dashboard')->with('status', 'تم تسجيل بدء الدوام بنجاح / Successfully clocked in!');
    }

    public function clockOut(Request $request, TimesheetService $service): RedirectResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        try {
            $service->clockOut(
                employee: $user,
                notes: $request->input('notes')
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withErrors(['attendance' => $e->getMessage()]);
        }

        return redirect()->route('employee.dashboard')->with('status', 'تم تسجيل إنهاء الدوام بنجاح / Successfully clocked out!');
    }
}
