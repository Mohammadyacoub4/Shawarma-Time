<x-app-layout>
    <x-slot name="header">
        <div class="page-header flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="page-title">{{ app()->getLocale() === 'ar' ? 'تقارير الحضور وساعات العمل' : 'Attendance & Timesheet Reports' }}</h1>
                <p class="text-xs text-dark-400 mt-0.5">
                    {{ app()->getLocale() === 'ar' ? 'تصفية وتصدير سجلات الدوام، التأخير، وساعات العمل الفعلية' : 'Filter, review, and export attendance records, lateness, and working hours' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['format' => 'csv'])) }}" class="btn-secondary text-xs flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.5V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    {{ app()->getLocale() === 'ar' ? 'تصدير CSV' : 'Export CSV' }}
                </a>
                <a href="{{ route('admin.reports.export', array_merge(request()->query(), ['format' => 'xlsx'])) }}" class="btn-secondary text-xs flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.5V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    {{ app()->getLocale() === 'ar' ? 'تصدير Excel (XLSX)' : 'Export XLSX' }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-flash-messages />

        {{-- FILTERS CARD --}}
        <div class="card">
            <div class="card-body">
                <form method="GET" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                        <div>
                            <x-input-label for="from" :value="app()->getLocale() === 'ar' ? 'من تاريخ' : 'From Date'" />
                            <x-text-input type="date" name="from" id="from" value="{{ $filters['from'] }}" class="w-full text-xs" />
                        </div>
                        <div>
                            <x-input-label for="to" :value="app()->getLocale() === 'ar' ? 'إلى تاريخ' : 'To Date'" />
                            <x-text-input type="date" name="to" id="to" value="{{ $filters['to'] }}" class="w-full text-xs" />
                        </div>
                        <div>
                            <x-input-label for="branch_id" :value="app()->getLocale() === 'ar' ? 'الفرع' : 'Branch'" />
                            <select name="branch_id" id="branch_id" class="form-select w-full text-xs">
                                <option value="">{{ app()->getLocale() === 'ar' ? 'جميع الفروع' : 'All branches' }}</option>
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected($filters['branch_id'] == $branch->id)>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="user_id" :value="app()->getLocale() === 'ar' ? 'الموظف' : 'Employee'" />
                            <select name="user_id" id="user_id" class="form-select w-full text-xs">
                                <option value="">{{ app()->getLocale() === 'ar' ? 'جميع الموظفين' : 'All employees' }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}" @selected($filters['user_id'] == $employee->id)>{{ $employee->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="status" :value="app()->getLocale() === 'ar' ? 'الحالة' : 'Status'" />
                            <select name="status" id="status" class="form-select w-full text-xs">
                                <option value="">{{ app()->getLocale() === 'ar' ? 'جميع الحالات' : 'All statuses' }}</option>
                                <option value="approved" @selected($filters['status'] === 'approved')>{{ app()->getLocale() === 'ar' ? 'معتمد' : 'Approved' }}</option>
                                <option value="pending_approval" @selected($filters['status'] === 'pending_approval')>{{ app()->getLocale() === 'ar' ? 'بانتظار الاعتماد' : 'Pending' }}</option>
                                <option value="clocked_in" @selected($filters['status'] === 'clocked_in')>{{ app()->getLocale() === 'ar' ? 'بالعمل الآن' : 'Clocked In' }}</option>
                                <option value="rejected" @selected($filters['status'] === 'rejected')>{{ app()->getLocale() === 'ar' ? 'مرفوض' : 'Rejected' }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4 pt-2 border-t border-dark-700/60">
                        <div class="flex flex-wrap items-center gap-5">
                            <label class="flex items-center gap-2 cursor-pointer text-xs text-dark-300 hover:text-white">
                                <input type="checkbox" name="late_only" value="1" @checked(!empty($filters['late_only'])) class="rounded bg-dark-700 border-dark-600 text-brand-500 focus:ring-brand-500/30">
                                <span>{{ app()->getLocale() === 'ar' ? 'المتأخرون فقط' : 'Late arrivals only' }}</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-xs text-dark-300 hover:text-white">
                                <input type="checkbox" name="missing_checkout" value="1" @checked(!empty($filters['missing_checkout'])) class="rounded bg-dark-700 border-dark-600 text-brand-500 focus:ring-brand-500/30">
                                <span>{{ app()->getLocale() === 'ar' ? 'سجلات بدون خروج (مفقود)' : 'Missing check-out only' }}</span>
                            </label>
                        </div>

                        <div class="flex items-center gap-2">
                            <x-primary-button class="text-xs !py-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z" />
                                </svg>
                                {{ app()->getLocale() === 'ar' ? 'تطبيق الفلترة' : 'Apply Filter' }}
                            </x-primary-button>
                            @if(request()->hasAny(['from', 'to', 'branch_id', 'user_id', 'status', 'late_only', 'missing_checkout']))
                                <a href="{{ route('admin.reports.index') }}" class="btn-secondary text-xs !py-2">
                                    {{ app()->getLocale() === 'ar' ? 'إعادة ضبط' : 'Reset' }}
                                </a>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- SUMMARY STATS --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'إجمالي الساعات المعتمدة' : 'Approved Working Hours' }}</p>
                <p class="stat-value text-emerald-400 mt-1">
                    {{ $calculator->formatHours($report['approved_combined_minutes']) }}
                </p>
                <p class="text-xs text-dark-400 mt-1">{{ app()->getLocale() === 'ar' ? 'ساعات دوام رسمية مؤكدة' : 'Confirmed approved hours' }}</p>
            </div>

            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'ساعات بانتظار الاعتماد' : 'Pending Hours' }}</p>
                <p class="stat-value text-amber-400 mt-1">
                    {{ $calculator->formatHours($report['pending_minutes']) }}
                </p>
                <p class="text-xs text-dark-400 mt-1">{{ app()->getLocale() === 'ar' ? 'تنتظر مراجعة الإدارة' : 'Awaiting manager approval' }}</p>
            </div>

            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'حالات التأخير' : 'Late Arrivals' }}</p>
                <p class="stat-value {{ ($report['late_count'] ?? 0) > 0 ? 'text-rose-400' : 'text-emerald-400' }} mt-1">
                    {{ $report['late_count'] ?? 0 }}
                    <span class="text-xs font-normal text-dark-400">({{ $report['late_minutes'] ?? 0 }} {{ app()->getLocale() === 'ar' ? 'دقيقة إجمالاً' : 'min total' }})</span>
                </p>
                <p class="text-xs text-dark-400 mt-1">{{ app()->getLocale() === 'ar' ? 'تجاوز فترة السماح المحددة' : 'Exceeded grace period' }}</p>
            </div>

            <div class="stat-card">
                <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'خروج غير مسجل' : 'Missing Check-outs' }}</p>
                <p class="stat-value {{ ($report['missing_checkout_count'] ?? 0) > 0 ? 'text-amber-400' : 'text-emerald-400' }} mt-1">
                    {{ $report['missing_checkout_count'] ?? 0 }}
                </p>
                <p class="text-xs text-dark-400 mt-1">{{ app()->getLocale() === 'ar' ? 'دوام جارٍ أو غير مكتمل' : 'Open / incomplete records' }}</p>
            </div>
        </div>

        {{-- BRANCH BREAKDOWN (IF ANY) --}}
        @if (!empty($report['by_branch']))
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach ($report['by_branch'] as $row)
                    <div class="card !p-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-semibold text-white text-sm">{{ $row['branch']->name }}</span>
                            <span class="badge bg-brand-500/10 text-brand-400 border border-brand-500/20 text-[10px]">
                                {{ $row['branch']->timezone }}
                            </span>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center text-xs">
                            <div class="bg-dark-700/50 p-2 rounded">
                                <span class="text-dark-400 block text-[10px]">{{ app()->getLocale() === 'ar' ? 'معتمد' : 'Approved' }}</span>
                                <span class="font-bold text-emerald-400 font-mono">{{ $calculator->formatHours($row['approved_minutes']) }}</span>
                            </div>
                            <div class="bg-dark-700/50 p-2 rounded">
                                <span class="text-dark-400 block text-[10px]">{{ app()->getLocale() === 'ar' ? 'معلق' : 'Pending' }}</span>
                                <span class="font-bold text-amber-400 font-mono">{{ $calculator->formatHours($row['pending_minutes']) }}</span>
                            </div>
                            <div class="bg-dark-700/50 p-2 rounded">
                                <span class="text-dark-400 block text-[10px]">{{ app()->getLocale() === 'ar' ? 'المجدول' : 'Scheduled' }}</span>
                                <span class="font-bold text-blue-400 font-mono">{{ $calculator->formatHours($row['scheduled_minutes']) }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- TABLE RESULTS --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-white text-base">
                        {{ app()->getLocale() === 'ar' ? 'تفاصيل سجلات الحضور' : 'Attendance Records Table' }}
                    </h3>
                    <p class="text-xs text-dark-400 mt-0.5">
                        {{ app()->getLocale() === 'ar' ? 'عرض السجلات المطابقة لمعايير البحث مع ترقيم الصفحات' : 'Showing matching attendance entries with pagination' }}
                    </p>
                </div>
                <span class="text-xs text-dark-400">
                    {{ $paginatedEntries->total() }} {{ app()->getLocale() === 'ar' ? 'سجل' : Str::plural('record', $paginatedEntries->total()) }}
                </span>
            </div>

            <div class="card-body">
                @include('partials.timesheet-table', [
                    'entries' => $paginatedEntries,
                    'admin' => true,
                ])

                @if ($paginatedEntries->hasPages())
                    <div class="mt-6 pt-4 border-t border-dark-700">
                        {{ $paginatedEntries->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
