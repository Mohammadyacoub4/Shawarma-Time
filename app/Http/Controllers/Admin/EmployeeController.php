<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\TemporaryPasswordManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
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
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('branches', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('branch_id')) {
            $query->whereHas('branches', fn ($q) => $q->where('branches.id', $request->integer('branch_id')));
        }

        // Support both 'status' ('active' / 'inactive') and 'active' (boolean / int)
        if ($request->filled('status')) {
            $status = strtolower($request->string('status')->trim()->toString());
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        } elseif ($request->has('active') && $request->input('active') !== '' && $request->input('active') !== null) {
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

    public function store(StoreEmployeeRequest $request, TemporaryPasswordManager $passwordManager): RedirectResponse
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

        $token = $passwordManager->store($request->user(), $employee, $password);

        $statusMsg = app()->getLocale() === 'ar'
            ? "تم إنشاء الموظف ({$employee->name}) بنجاح. انقر أدناه لعرض كلمة المرور لمرة واحدة فقط."
            : "Employee {$employee->name} created successfully. Click below to view the temporary password once.";

        return redirect()
            ->route('admin.employees.index')
            ->with('status', $statusMsg)
            ->with('temp_password_pending', [
                'token' => $token,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
            ]);
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

        $newActiveStatus = ! $employee->is_active;

        $employee->update([
            'is_active' => $newActiveStatus,
        ]);

        if (! $newActiveStatus && config('session.driver') === 'database') {
            try {
                $sessionTable = (string) config('session.table', 'sessions');
                $connection = config('session.connection');

                if ($sessionTable !== '' && Schema::connection($connection)->hasTable($sessionTable)) {
                    DB::connection($connection)
                        ->table($sessionTable)
                        ->where('user_id', $employee->id)
                        ->delete();
                }
            } catch (\Throwable) {
                // Safely handle any session table query or connection failure
            }
        }

        $statusMsg = $employee->is_active
            ? 'تم تفعيل حساب الموظف بنجاح / Employee activated.'
            : 'تم إلغاء تفعيل حساب الموظف / Employee deactivated.';

        return back()->with('status', $statusMsg);
    }

    public function showResetPassword(User $employee): View
    {
        abort_unless($employee->isEmployee(), 404);
        $this->authorize('resetPassword', $employee);

        return view('admin.employees.reset-password', [
            'employee' => $employee->load('branches'),
        ]);
    }

    public function resetPassword(Request $request, User $employee, TemporaryPasswordManager $passwordManager): RedirectResponse
    {
        abort_unless($employee->isEmployee(), 404);
        $this->authorize('resetPassword', $employee);

        if ($request->filled('password')) {
            $validated = $request->validate([
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'must_change_password' => ['sometimes', 'boolean'],
            ]);

            $password = $validated['password'];
            $mustChange = $request->boolean('must_change_password', false);
        } else {
            $password = Str::password(16);
            $mustChange = true;
        }

        $employee->update([
            'password' => Hash::make($password),
            'must_change_password' => $mustChange,
        ]);

        $token = $passwordManager->store($request->user(), $employee, $password);

        $msg = app()->getLocale() === 'ar'
            ? "تم تعيين كلمة المرور الجديدة للموظف ({$employee->name}) بنجاح. انقر أدناه لعرض كلمة المرور لمرة واحدة فقط."
            : "Password updated successfully for {$employee->name}. Click below to view the temporary password once.";

        return redirect()->route('admin.employees.show', $employee)
            ->with('status', $msg)
            ->with('temp_password_pending', [
                'token' => $token,
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
            ]);
    }

    public function retrieveTemporaryPassword(Request $request, User $employee, TemporaryPasswordManager $passwordManager): JsonResponse
    {
        abort_unless($employee->isEmployee(), 404);
        $this->authorize('resetPassword', $employee);

        $token = (string) $request->input('token', '');
        if ($token === '') {
            return response()->json(['error' => 'Missing token.'], 400);
        }

        $password = $passwordManager->retrieveOnce($request->user(), $employee, $token);

        if ($password === null) {
            return response()->json([
                'error' => app()->getLocale() === 'ar'
                    ? 'انتهت صلاحية كلمة المرور المؤقتة أو تم عرضها مسبقاً.'
                    : 'The temporary password has expired or has already been retrieved.',
            ], 410);
        }

        return response()->json([
            'password' => $password,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private')
          ->header('Pragma', 'no-cache');
    }
}
