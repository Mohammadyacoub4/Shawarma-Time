<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\TimesheetApprovalController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Employee\EmployeeDashboardController;
use App\Http\Controllers\Employee\ScheduleController as EmployeeScheduleController;
use App\Http\Controllers\Employee\TimesheetController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/locale/{lang}', function (string $lang) {
    if (in_array($lang, ['ar', 'en'])) {
        session(['locale' => $lang, 'direction' => $lang === 'ar' ? 'rtl' : 'ltr']);
    }

    return back();
})->name('locale.switch');

Route::middleware(['auth', 'active', 'password.change'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/password/change', [ForcePasswordChangeController::class, 'edit'])->name('password.force');
    Route::put('/password/change', [ForcePasswordChangeController::class, 'update'])->name('password.force.update');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');
        Route::resource('employees', EmployeeController::class)->except(['destroy']);
        Route::post('employees/{employee}/toggle-status', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle-status');
        Route::get('employees/{employee}/reset-password', [EmployeeController::class, 'showResetPassword'])->name('employees.reset-password');
        Route::post('employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.reset-password.update');

        Route::get('schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::post('schedules/shifts', [ScheduleController::class, 'store'])->name('schedules.shifts.store');
        Route::post('schedules/batch-store', [ScheduleController::class, 'batchStore'])->name('schedules.batch-store');
        Route::put('schedules/shifts/{shift}', [ScheduleController::class, 'update'])->name('schedules.shifts.update');
        Route::delete('schedules/shifts/{shift}', [ScheduleController::class, 'destroy'])->name('schedules.shifts.destroy');
        Route::post('schedules/publish', [ScheduleController::class, 'publish'])->name('schedules.publish');
        Route::post('schedules/unpublish', [ScheduleController::class, 'unpublish'])->name('schedules.unpublish');
        Route::post('schedules/copy-previous', [ScheduleController::class, 'copyPreviousWeek'])->name('schedules.copy-previous');
        Route::post('schedules/clear-week', [ScheduleController::class, 'clearWeek'])->name('schedules.clear-week');

        Route::get('timesheets', [TimesheetApprovalController::class, 'index'])->name('timesheets.index');
        Route::get('timesheets/{timesheet}', [TimesheetApprovalController::class, 'show'])->name('timesheets.show');
        Route::put('timesheets/{timesheet}', [TimesheetApprovalController::class, 'update'])->name('timesheets.update');
        Route::post('timesheets/{timesheet}/approve', [TimesheetApprovalController::class, 'approve'])->name('timesheets.approve');
        Route::post('timesheets/{timesheet}/reject', [TimesheetApprovalController::class, 'reject'])->name('timesheets.reject');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    Route::prefix('employee')->name('employee.')->group(function () {
        Route::get('/dashboard', EmployeeDashboardController::class)->name('dashboard');
        Route::post('/attendance/clock-in', [\App\Http\Controllers\Employee\AttendanceController::class, 'clockIn'])->name('attendance.clock-in');
        Route::post('/attendance/clock-out', [\App\Http\Controllers\Employee\AttendanceController::class, 'clockOut'])->name('attendance.clock-out');
        Route::get('/schedules', [EmployeeScheduleController::class, 'index'])->name('schedules.index');
        Route::get('/timesheets/create', [TimesheetController::class, 'create'])->name('timesheets.create');
        Route::post('/timesheets', [TimesheetController::class, 'store'])->name('timesheets.store');
        Route::get('/timesheets/{timesheet}/edit', [TimesheetController::class, 'edit'])->name('timesheets.edit');
        Route::put('/timesheets/{timesheet}', [TimesheetController::class, 'update'])->name('timesheets.update');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
