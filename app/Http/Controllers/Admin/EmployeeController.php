<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::query()->where('role', UserRole::Employee)->with('branches');

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('branch_id')) {
            $query->whereHas('branches', fn ($q) => $q->where('branches.id', $request->integer('branch_id')));
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return view('admin.employees.index', [
            'employees' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.employees.create', [
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $password = $request->filled('password')
            ? $request->string('password')->toString()
            : Str::password(16);

        $employee = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'phone' => $request->input('phone'),
            'password' => Hash::make($password),
            'role' => UserRole::Employee,
            'is_active' => true,
            'must_change_password' => $request->boolean('must_change_password', true),
        ]);

        $employee->branches()->sync($request->input('branch_ids'));

        return redirect()
            ->route('admin.employees.index')
            ->with('status', "Employee created. Temporary password: {$password} (share securely and ask them to change it at first login).");
    }

    public function edit(User $employee): View
    {
        abort_unless($employee->isEmployee(), 404);

        return view('admin.employees.edit', [
            'employee' => $employee->load('branches'),
            'branches' => Branch::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, User $employee): RedirectResponse
    {
        abort_unless($employee->isEmployee(), 404);

        $employee->update([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'phone' => $request->input('phone'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $employee->branches()->sync($request->input('branch_ids'));

        return redirect()->route('admin.employees.index')->with('status', 'Employee updated.');
    }

    public function show(User $employee): View
    {
        abort_unless($employee->isEmployee(), 404);

        $attendanceEntries = $employee->timesheetEntries()
            ->with(['branch', 'reviewer'])
            ->latest('work_date')
            ->latest('starts_at')
            ->paginate(15);

        $totalApprovedMinutes = $employee->timesheetEntries()
            ->where('status', \App\Enums\TimesheetStatus::Approved)
            ->sum('total_minutes');

        $totalLateCount = $employee->timesheetEntries()
            ->where('is_late', true)
            ->count();

        $activeClockIn = $employee->activeClockIn();

        return view('admin.employees.show', [
            'employee' => $employee->load('branches'),
            'attendanceEntries' => $attendanceEntries,
            'totalApprovedMinutes' => (int) $totalApprovedMinutes,
            'totalLateCount' => $totalLateCount,
            'activeClockIn' => $activeClockIn,
        ]);
    }

    public function toggleStatus(User $employee): RedirectResponse
    {
        abort_unless($employee->isEmployee(), 404);

        $employee->update([
            'is_active' => ! $employee->is_active,
        ]);

        $statusMsg = $employee->is_active
            ? 'تم تفعيل حساب الموظف بنجاح / Employee activated.'
            : 'تم إلغاء تفعيل حساب الموظف / Employee deactivated.';

        return back()->with('status', $statusMsg);
    }

    public function resetPassword(User $employee): RedirectResponse
    {
        abort_unless($employee->isEmployee(), 404);
        $this->authorize('resetPassword', $employee);

        $password = Str::password(16);
        $employee->update([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ]);

        return back()->with('status', "Password reset. New temporary password: {$password}");
    }
}
