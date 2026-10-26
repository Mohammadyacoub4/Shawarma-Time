<x-app-layout>
    <x-slot name="header">
        <div class="page-header flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="page-title text-xl sm:text-2xl font-bold text-white flex items-center gap-2">
                    <span>{{ app()->getLocale() === 'ar' ? 'لوحة تحكم الإدارة' : 'Admin Dashboard' }}</span>
                    <span class="badge bg-brand-500/20 text-brand-400 border border-brand-500/30 text-xs">
                        {{ config('app.timezone', 'Asia/Amman') }}
                    </span>
                </h1>
                <p class="text-sm text-dark-400 mt-1">
                    {{ app()->getLocale() === 'ar' ? 'متابعة الحضور المباشر، الغياب، التأخير، ومناوبات الفروع' : 'Live staff attendance, absences, lateness & branch schedules' }}
                </p>
            </div>

            {{-- DATE FILTER & QUICK ACTIONS --}}
            <div class="flex flex-wrap items-center gap-2">
                <form method="GET" action="{{ route('admin.dashboard') }}" class="flex items-center gap-2 bg-dark-800 p-1 rounded-xl border border-dark-700">
                    <input type="date"
                           name="date"
                           value="{{ $selectedDate }}"
                           onchange="this.form.submit()"
                           class="bg-dark-900 border border-dark-700 text-xs text-white rounded-lg px-2.5 py-1.5 focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
                    @if($selectedDate !== $today)
                        <a href="{{ route('admin.dashboard') }}" class="text-xs text-brand-400 hover:text-brand-300 px-2 py-1">
                            {{ app()->getLocale() === 'ar' ? 'اليوم' : 'Today' }}
                        </a>
                    @endif
                </form>

                <a href="{{ route('admin.reports.index', ['from' => $selectedDate, 'to' => $selectedDate]) }}" class="btn-secondary text-xs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    {{ app()->getLocale() === 'ar' ? 'تقرير التاريخ' : 'Date Report' }}
                </a>

                <a href="{{ route('admin.schedules.index') }}" class="btn-primary text-xs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    {{ app()->getLocale() === 'ar' ? 'إدارة الجداول' : 'Schedules' }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-flash-messages />

        {{-- 4 MAIN METRIC CARDS REQUIRED BY SPEC --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- 1. Total Active Employees --}}
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'إجمالي الموظفين النشطين' : 'Total Active Employees' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-brand-500/10 flex items-center justify-center text-brand-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                    </div>
                </div>
                <p class="stat-value">{{ $activeEmployees }}</p>
                <a href="{{ route('admin.employees.index') }}" class="text-xs text-brand-400 hover:text-brand-300 transition-colors flex items-center gap-1">
                    {{ app()->getLocale() === 'ar' ? 'إدارة الموظفين ←' : 'Manage employees →' }}
                </a>
            </div>

            {{-- 2. Employees currently clocked in --}}
            <div class="stat-card border-emerald-500/30">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'المتواجدون بالعمل الآن' : 'Currently Clocked In' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400">
                        <span class="relative flex h-3.5 w-3.5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500"></span>
                        </span>
                    </div>
                </div>
                <p class="stat-value text-emerald-400">{{ $currentlyClockedIn->count() }}</p>
                <p class="text-xs text-dark-400">
                    {{ app()->getLocale() === 'ar' ? 'حضور مباشر لحظي' : 'Live attendance' }}
                </p>
            </div>

            {{-- 3. Absent Today (reliably computed from schedules) --}}
            <div class="stat-card {{ $absentShifts->count() > 0 ? 'border-rose-500/30' : '' }}">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'الغائبون (حسب الجدول)' : 'Absent (Scheduled)' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-rose-500/10 flex items-center justify-center text-rose-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                    </div>
                </div>
                <p class="stat-value {{ $absentShifts->count() > 0 ? 'text-rose-400' : 'text-dark-200' }}">
                    {{ $absentShifts->count() }}
                </p>
                <p class="text-xs text-dark-500">
                    {{ app()->getLocale() === 'ar' ? 'بدأ وقت مناوبتهم ولم يسجلوا' : 'Passed shift start time' }}
                </p>
            </div>

            {{-- 4. Late Arrivals Today --}}
            <div class="stat-card {{ $lateArrivals->count() > 0 ? 'border-amber-500/30' : '' }}">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'حالات التأخير' : 'Late Arrivals' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-amber-500/10 flex items-center justify-center text-amber-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                </div>
                <p class="stat-value {{ $lateArrivals->count() > 0 ? 'text-amber-400' : 'text-dark-200' }}">
                    {{ $lateArrivals->count() }}
                </p>
                <p class="text-xs text-dark-500">
                    {{ app()->getLocale() === 'ar' ? 'تجاوزوا فترة السماح المحددة' : 'Exceeded shift grace period' }}
                </p>
            </div>
        </div>

        {{-- ROW 2: LIVE CLOCKED IN STAFF & ABSENT EMPLOYEES --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- CURRENTLY CLOCKED IN LIST --}}
            <div class="card border border-dark-700">
                <div class="card-header flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <h3 class="font-bold text-white text-sm">
                            {{ app()->getLocale() === 'ar' ? 'المتواجدون على رأس عملهم الآن' : 'Employees Currently Clocked In' }}
                            ({{ $currentlyClockedIn->count() }})
                        </h3>
                    </div>
                    <span class="text-xs text-dark-400">{{ app()->getLocale() === 'ar' ? 'مباشر' : 'Live' }}</span>
                </div>
                <div class="card-body p-0">
                    @if($currentlyClockedIn->isEmpty())
                        <div class="py-8 text-center text-dark-500 text-sm">
                            {{ app()->getLocale() === 'ar' ? 'لا يوجد موظفون مسجلون حضوراً في هذا الوقت.' : 'No employees are currently clocked in.' }}
                        </div>
                    @else
                        <div class="divide-y divide-dark-700/60 max-h-72 overflow-y-auto">
                            @foreach($currentlyClockedIn as $entry)
                                <div class="p-3.5 flex items-center justify-between hover:bg-dark-700/30 transition-colors">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-400 font-bold flex items-center justify-center text-xs">
                                            {{ mb_substr($entry->user->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-white">{{ $entry->user->name }}</p>
                                            <p class="text-xs text-dark-400 font-mono">
                                                {{ app()->getLocale() === 'ar' ? 'منذ:' : 'Since:' }}
                                                {{ $entry->starts_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}
                                                ({{ $entry->starts_at->diffForHumans() }})
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-brand-500/10 text-brand-400 text-xs">
                                            {{ $entry->branch->name }}
                                        </span>
                                        @if($entry->is_late)
                                            <span class="block text-[10px] text-amber-400 font-medium mt-0.5">
                                                {{ app()->getLocale() === 'ar' ? 'متأخر' : 'Late' }} (+{{ $entry->late_minutes }}m)
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- ABSENT EMPLOYEES LIST (SCHEDULED TODAY BUT DID NOT CLOCK IN) --}}
            <div class="card border border-dark-700">
                <div class="card-header flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        <h3 class="font-bold text-white text-sm">
                            {{ app()->getLocale() === 'ar' ? 'حالات الغياب المكتشفة اليوم' : 'Absences Detected Today' }}
                            ({{ $absentShifts->count() }})
                        </h3>
                    </div>
                    <span class="text-xs text-dark-400">{{ app()->getLocale() === 'ar' ? 'حسب الجداول المعتمدة' : 'Based on Schedule' }}</span>
                </div>
                <div class="card-body p-0">
                    @if($absentShifts->isEmpty())
                        <div class="py-8 text-center text-dark-500 text-sm">
                            {{ app()->getLocale() === 'ar' ? 'لا توجد غيابات مسجلة حتى الآن لهذا اليوم.' : 'No absences detected for this date.' }}
                        </div>
                    @else
                        <div class="divide-y divide-dark-700/60 max-h-72 overflow-y-auto">
                            @foreach($absentShifts as $shift)
                                <div class="p-3.5 flex items-center justify-between hover:bg-dark-700/30 transition-colors">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-rose-500/20 text-rose-400 font-bold flex items-center justify-center text-xs">
                                            {{ mb_substr($shift->user->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-white">{{ $shift->user->name }}</p>
                                            <p class="text-xs text-rose-400/90 font-mono">
                                                {{ app()->getLocale() === 'ar' ? 'موعد المناوبة:' : 'Shift:' }}
                                                {{ $shift->starts_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }} –
                                                {{ $shift->ends_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-rose-500/10 text-rose-400 border border-rose-500/20 text-xs">
                                            {{ app()->getLocale() === 'ar' ? 'غائب' : 'Absent' }}
                                        </span>
                                        <span class="block text-xs text-dark-400 mt-0.5">{{ $shift->branch->name }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ROW 3: TODAY'S ATTENDANCE ACTIVITY & RECENT LOGS --}}
        <div class="card border border-dark-700">
            <div class="card-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <h3 class="font-bold text-white text-sm">
                        {{ app()->getLocale() === 'ar' ? 'نشاط الحضور والانصراف لتاريخ:' : 'Attendance Activity for Date:' }}
                        <span class="text-brand-400 font-mono">{{ $selectedDate }}</span>
                    </h3>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.timesheets.index') }}" class="btn-secondary text-xs">
                        {{ app()->getLocale() === 'ar' ? 'طلبات الموافقة المعلقة (' . $pendingCount . ')' : 'Pending Approvals (' . $pendingCount . ')' }}
                    </a>
                    <a href="{{ route('admin.reports.index', ['from' => $selectedDate, 'to' => $selectedDate]) }}" class="btn-primary text-xs">
                        {{ app()->getLocale() === 'ar' ? 'تقرير تفصيلي ←' : 'Detailed Report →' }}
                    </a>
                </div>
            </div>
            <div class="card-body">
                @include('partials.timesheet-table', ['entries' => $attendanceActivity, 'admin' => true])
            </div>
        </div>

        {{-- ROW 4: BRANCH DISTRIBUTION & APPROVED HOURS --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="card">
                <div class="card-header flex items-center gap-2">
                    <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" /></svg>
                    <h3 class="font-semibold text-white text-sm">{{ app()->getLocale() === 'ar' ? 'الموظفون حسب الفرع' : 'Employees by Branch' }}</h3>
                </div>
                <div class="card-body space-y-3">
                    @foreach ($branches as $branch)
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 rounded-full bg-brand-500"></div>
                                <span class="text-sm font-medium text-dark-200">{{ $branch->name }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs font-mono text-dark-400">{{ $branch->active_employees_count }} {{ app()->getLocale() === 'ar' ? 'موظف' : 'staff' }}</span>
                                <div class="h-2 w-24 bg-dark-700 rounded-full overflow-hidden">
                                    <div class="h-full bg-brand-500 rounded-full transition-all"
                                         style="width: {{ $activeEmployees > 0 ? ($branch->active_employees_count / $activeEmployees * 100) : 0 }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <h3 class="font-semibold text-white text-sm">{{ app()->getLocale() === 'ar' ? 'ساعات العمل المعتمدة هذا الأسبوع' : 'Approved Hours This Week' }}</h3>
                    </div>
                    <span class="text-xs text-dark-500">{{ $weekStart }} – {{ $weekEnd }}</span>
                </div>
                <div class="card-body space-y-3">
                    @foreach ($branches as $branch)
                        @php $mins = $approvedByBranch[$branch->id] ?? 0; @endphp
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
                                <span class="text-sm text-dark-200">{{ $branch->name }}</span>
                            </div>
                            <span class="font-mono text-sm font-semibold text-green-400">
                                {{ sprintf('%d:%02d', intdiv($mins, 60), $mins % 60) }} {{ app()->getLocale() === 'ar' ? 'ساعة' : 'hrs' }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
