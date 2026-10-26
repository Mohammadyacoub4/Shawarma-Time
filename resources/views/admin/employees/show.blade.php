<x-app-layout>
    <x-slot name="header">
        <div class="page-header flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <a href="{{ route('admin.employees.index') }}" class="btn-secondary !p-2">
                    <svg class="w-5 h-5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                </a>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="page-title">{{ $employee->name }}</h1>
                        @if ($employee->is_active)
                            <span class="badge bg-emerald-500/15 text-emerald-400 ring-1 ring-emerald-500/25">
                                {{ app()->getLocale() === 'ar' ? 'نشط' : 'Active' }}
                            </span>
                        @else
                            <span class="badge bg-dark-600/50 text-dark-400 ring-1 ring-dark-500/30">
                                {{ app()->getLocale() === 'ar' ? 'غير نشط' : 'Inactive' }}
                            </span>
                        @endif

                        @if ($activeClockIn)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                                {{ app()->getLocale() === 'ar' ? 'بالعمل الآن' : 'Clocked In' }}
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-dark-400 mt-1">{{ $employee->email }} • {{ $employee->phone ?? (app()->getLocale() === 'ar' ? 'بدون هاتف' : 'No phone') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.employees.edit', $employee) }}" class="btn-secondary text-xs">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                    </svg>
                    {{ app()->getLocale() === 'ar' ? 'تعديل البيانات' : 'Edit' }}
                </a>

                <form action="{{ route('admin.employees.toggle-status', $employee) }}" method="POST" class="inline"
                      onsubmit="return confirm('{{ $employee->is_active ? (app()->getLocale() === 'ar' ? 'هل أنت متأكد من تعطيل هذا الحساب؟' : 'Deactivate this employee?') : (app()->getLocale() === 'ar' ? 'هل أنت متأكد من تفعيل هذا الحساب؟' : 'Activate this employee?') }}');">
                    @csrf
                    <button type="submit" class="btn-secondary text-xs {{ $employee->is_active ? 'hover:text-red-400' : 'hover:text-emerald-400' }}">
                        @if ($employee->is_active)
                            {{ app()->getLocale() === 'ar' ? 'تعطيل الحساب' : 'Deactivate' }}
                        @else
                            {{ app()->getLocale() === 'ar' ? 'تفعيل الحساب' : 'Activate' }}
                        @endif
                    </button>
                </form>

                <form action="{{ route('admin.employees.reset-password', $employee) }}" method="POST" class="inline"
                      onsubmit="return confirm('{{ app()->getLocale() === 'ar' ? 'هل تريد إعادة تعيين كلمة المرور لهذا الموظف؟' : 'Reset password for this employee?' }}');">
                    @csrf
                    <button type="submit" class="btn-secondary text-xs text-amber-400 hover:text-amber-300">
                        {{ app()->getLocale() === 'ar' ? 'إعادة تعيين كلمة المرور' : 'Reset Password' }}
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-flash-messages />

        {{-- KPI STATS --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'حالة التواجد الحالية' : 'Current Status' }}</p>
                <div class="mt-2">
                    @if ($activeClockIn)
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-lg font-bold text-emerald-400">{{ app()->getLocale() === 'ar' ? 'بالعمل الآن' : 'Clocked In' }}</span>
                        </div>
                        <p class="text-xs text-dark-400 mt-1">
                            {{ $activeClockIn->branch->name }} • {{ app()->getLocale() === 'ar' ? 'منذ' : 'since' }}
                            {{ $activeClockIn->starts_at?->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}
                        </p>
                    @else
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-dark-500"></span>
                            <span class="text-lg font-bold text-dark-300">{{ app()->getLocale() === 'ar' ? 'غير مسجل حضور' : 'Not Clocked In' }}</span>
                        </div>
                        <p class="text-xs text-dark-500 mt-1">{{ app()->getLocale() === 'ar' ? 'لا يوجد دوام جارٍ' : 'No active shift' }}</p>
                    @endif
                </div>
            </div>

            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'إجمالي الساعات المعتمدة' : 'Total Approved Hours' }}</p>
                <p class="stat-value text-white mt-1">
                    {{ sprintf('%d:%02d', intdiv($totalApprovedMinutes, 60), $totalApprovedMinutes % 60) }}
                    <span class="text-xs font-normal text-dark-400">{{ app()->getLocale() === 'ar' ? 'ساعة' : 'hrs' }}</span>
                </p>
                <p class="text-xs text-dark-400 mt-1">{{ app()->getLocale() === 'ar' ? 'سجلات معتمدة رسمياً' : 'From approved timesheets' }}</p>
            </div>

            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'مرات التأخير المسجلة' : 'Late Arrivals' }}</p>
                <p class="stat-value {{ $totalLateCount > 0 ? 'text-amber-400' : 'text-emerald-400' }} mt-1">
                    {{ $totalLateCount }}
                </p>
                <p class="text-xs text-dark-400 mt-1">{{ app()->getLocale() === 'ar' ? 'تجاوز فترة السماح المحددة' : 'Exceeded shift grace period' }}</p>
            </div>

            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'الفروع المصرح بها' : 'Assigned Branches' }}</p>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    @forelse ($employee->branches as $branch)
                        <span class="badge bg-brand-500/10 text-brand-400 border border-brand-500/20 text-xs">
                            {{ $branch->name }}
                        </span>
                    @empty
                        <span class="text-xs text-dark-500">{{ app()->getLocale() === 'ar' ? 'غير مخصص لأي فرع' : 'No branches' }}</span>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ATTENDANCE HISTORY TABLE --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-white text-base">
                        {{ app()->getLocale() === 'ar' ? 'سجل الحضور والانصراف التفصيلي' : 'Attendance & Timesheet History' }}
                    </h3>
                    <p class="text-xs text-dark-400 mt-0.5">
                        {{ app()->getLocale() === 'ar' ? 'جميع حركات تسجيل الدخول والخروج الخاصة بالموظف' : 'Full history of employee clock-in and clock-out actions' }}
                    </p>
                </div>
                <span class="text-xs text-dark-400">
                    {{ $attendanceEntries->total() }} {{ app()->getLocale() === 'ar' ? 'سجل' : Str::plural('record', $attendanceEntries->total()) }}
                </span>
            </div>

            <div class="card-body">
                @include('partials.timesheet-table', [
                    'entries' => $attendanceEntries,
                    'admin' => false,
                ])

                @if ($attendanceEntries->hasPages())
                    <div class="mt-6 pt-4 border-t border-dark-700">
                        {{ $attendanceEntries->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
