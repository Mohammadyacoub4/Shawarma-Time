<x-app-layout>
    <x-slot name="header">
        <div class="page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="page-title text-xl sm:text-2xl font-bold text-white flex items-center gap-2">
                    <span>{{ app()->getLocale() === 'ar' ? 'لوحة الموظف' : 'My Dashboard' }}</span>
                    @if($activeClockIn)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 animate-pulse">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            {{ app()->getLocale() === 'ar' ? 'بالعمل الآن' : 'Working Now' }}
                        </span>
                    @endif
                </h1>
                <p class="text-sm text-dark-400 mt-1">
                    {{ app()->getLocale() === 'ar' ? 'أهلاً بك، ' . Auth::user()->name : 'Welcome back, ' . Auth::user()->name }}
                    &nbsp;·&nbsp;
                    <span class="text-dark-400">{{ now()->timezone(config('app.timezone', 'Asia/Amman'))->translatedFormat('l, j F Y') }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('employee.schedules.index') }}" class="btn-secondary text-xs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    {{ app()->getLocale() === 'ar' ? 'جدول المناوبات' : 'View Schedule' }}
                </a>
                <a href="{{ route('employee.timesheets.create') }}" class="btn-secondary text-xs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ app()->getLocale() === 'ar' ? 'إدخال يدوي' : 'Manual Entry' }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-flash-messages />

        @if($errors->has('attendance'))
            <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 flex items-start gap-3">
                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                <div class="text-sm font-medium">{{ $errors->first('attendance') }}</div>
            </div>
        @endif

        {{-- ATTENDANCE CLOCK-IN / CLOCK-OUT CARD (HIGH VISIBILITY FOR MOBILE) --}}
        <div class="card overflow-hidden border-2 {{ $activeClockIn ? 'border-emerald-500/40 bg-gradient-to-b from-dark-800 to-dark-900' : 'border-dark-700 bg-dark-800' }}">
            <div class="p-5 sm:p-6">
                @if($activeClockIn)
                    {{-- CURRENTLY CLOCKED IN VIEW --}}
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6"
                         x-data="{
                             startTime: new Date('{{ $activeClockIn->starts_at->toIso8601String() }}').getTime(),
                             elapsed: '',
                             updateTimer() {
                                 const now = new Date().getTime();
                                 const diff = Math.max(0, Math.floor((now - this.startTime) / 1000));
                                 const hrs = Math.floor(diff / 3600);
                                 const mins = Math.floor((diff % 3600) / 60);
                                 const secs = diff % 60;
                                 this.elapsed = `${hrs.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                             }
                         }"
                         x-init="updateTimer(); setInterval(() => updateTimer(), 1000)">
                        <div class="space-y-2">
                            <div class="flex items-center gap-2">
                                <span class="relative flex h-3 w-3">
                                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                  <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                                </span>
                                <h3 class="text-base sm:text-lg font-bold text-emerald-400">
                                    {{ app()->getLocale() === 'ar' ? 'أنت متواجد بالعمل حالياً' : 'You are currently clocked in' }}
                                </h3>
                            </div>

                            <p class="text-sm text-dark-300">
                                <span class="text-dark-400">{{ app()->getLocale() === 'ar' ? 'الفرع:' : 'Branch:' }}</span>
                                <span class="font-semibold text-white">{{ $activeClockIn->branch->name }}</span>
                                &nbsp;·&nbsp;
                                <span class="text-dark-400">{{ app()->getLocale() === 'ar' ? 'وقت الدخول:' : 'Clocked In At:' }}</span>
                                <span class="font-mono text-white">{{ $activeClockIn->starts_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}</span>
                            </p>

                            @if($activeClockIn->is_late)
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                                    {{ app()->getLocale() === 'ar' ? 'تسجيل متأخر عن موعد المناوبة (+'.$activeClockIn->late_minutes.' دقيقة)' : 'Late arrival (+'.$activeClockIn->late_minutes.' mins)' }}
                                </div>
                            @endif

                            <div class="pt-2">
                                <span class="text-xs text-dark-400">{{ app()->getLocale() === 'ar' ? 'المدة المنقضية اليوم:' : 'Time Elapsed:' }}</span>
                                <div class="text-3xl sm:text-4xl font-extrabold font-mono text-white tracking-wider" x-text="elapsed">00:00:00</div>
                            </div>
                        </div>

                        {{-- CLOCK OUT FORM --}}
                        <form method="POST" action="{{ route('employee.attendance.clock-out') }}" class="w-full md:w-auto flex flex-col sm:flex-row items-stretch gap-3">
                            @csrf
                            <input type="text"
                                   name="notes"
                                   placeholder="{{ app()->getLocale() === 'ar' ? 'ملاحظة عند الانصراف (اختياري)...' : 'Clock out notes (optional)...' }}"
                                   class="px-4 py-3 rounded-xl bg-dark-900 border border-dark-700 text-sm text-white placeholder-dark-500 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 w-full sm:w-64">

                            <button type="submit"
                                    onclick="return confirm('{{ app()->getLocale() === 'ar' ? 'هل أنت متأكد من رغبتك في تسجيل الانصراف وإنهاء الدوام الآن؟' : 'Are you sure you want to clock out now?' }}')"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-base shadow-lg shadow-rose-900/40 hover:shadow-rose-700/50 transition-all duration-200 active:scale-95">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                                </svg>
                                <span>{{ app()->getLocale() === 'ar' ? 'تسجيل الانصراف (إنهاء الدوام)' : 'Clock Out' }}</span>
                            </button>
                        </form>
                    </div>
                @else
                    {{-- NOT CLOCKED IN VIEW --}}
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="w-3 h-3 rounded-full bg-dark-500"></span>
                                <h3 class="text-base sm:text-lg font-bold text-white">
                                    {{ app()->getLocale() === 'ar' ? 'أنت غير متواجد بالعمل حالياً' : 'You are currently not clocked in' }}
                                </h3>
                            </div>
                            <p class="text-sm text-dark-400">
                                {{ app()->getLocale() === 'ar' ? 'قم باختيار الفرع واضغط على زر تسجيل الحضور لبدء احتساب ساعات عملك.' : 'Select your branch and clock in to start tracking your working hours.' }}
                            </p>

                            @if($todayShift)
                                <div class="inline-flex items-center gap-2 mt-2 px-3 py-1.5 rounded-lg bg-brand-500/10 border border-brand-500/20 text-xs text-brand-300">
                                    <svg class="w-4 h-4 text-brand-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    <span>
                                        <strong>{{ app()->getLocale() === 'ar' ? 'مناوبتك اليوم:' : "Today's Scheduled Shift:" }}</strong>
                                        {{ $todayShift->branch->name }} ({{ $todayShift->starts_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }} – {{ $todayShift->ends_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }})
                                        @if($todayShift->grace_minutes)
                                            · <span class="text-dark-400">{{ app()->getLocale() === 'ar' ? 'فترة السماح:' : 'Grace Period:' }} {{ $todayShift->grace_minutes }} دقيقة</span>
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </div>

                        {{-- CLOCK IN FORM --}}
                        <form method="POST" action="{{ route('employee.attendance.clock-in') }}" class="w-full lg:w-auto flex flex-col sm:flex-row items-stretch gap-3">
                            @csrf
                            <select name="branch_id"
                                    required
                                    class="px-4 py-3 rounded-xl bg-dark-900 border border-dark-700 text-sm text-white focus:border-brand-500 focus:ring-1 focus:ring-brand-500">
                                @foreach($userBranches as $branch)
                                    <option value="{{ $branch->id }}" {{ $todayShift && $todayShift->branch_id === $branch->id ? 'selected' : '' }}>
                                        {{ $branch->name }}
                                    </option>
                                @endforeach
                            </select>

                            <input type="text"
                                   name="notes"
                                   placeholder="{{ app()->getLocale() === 'ar' ? 'ملاحظة (اختياري)...' : 'Notes (optional)...' }}"
                                   class="px-4 py-3 rounded-xl bg-dark-900 border border-dark-700 text-sm text-white placeholder-dark-500 focus:border-brand-500 focus:ring-1 focus:ring-brand-500 w-full sm:w-56">

                            <button type="submit"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-base shadow-lg shadow-emerald-900/40 hover:shadow-emerald-700/50 transition-all duration-200 active:scale-95">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>{{ app()->getLocale() === 'ar' ? 'تسجيل الحضور (بدء الدوام)' : 'Clock In' }}</span>
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        {{-- STATS ROW --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'ساعات اليوم المعتمدة' : 'Approved Today' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-green-500/10 flex items-center justify-center text-green-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                </div>
                <p class="stat-value text-green-400">{{ $calculator->formatHours($totals['daily_approved_minutes']) }}</p>
                <p class="text-xs text-dark-500">{{ now()->timezone(config('app.timezone', 'Asia/Amman'))->format('D, M j') }}</p>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'ساعات هذا الأسبوع' : 'Approved This Week' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-brand-500/10 flex items-center justify-center text-brand-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    </div>
                </div>
                <p class="stat-value">{{ $calculator->formatHours($totals['weekly_approved_minutes']) }}</p>
                <p class="text-xs text-dark-500">{{ app()->getLocale() === 'ar' ? 'إجمالي الأسبوع' : 'Weekly Total' }}</p>
            </div>

            <div class="stat-card">
                <div class="flex items-center justify-between">
                    <p class="stat-label">{{ app()->getLocale() === 'ar' ? 'ساعات هذا الشهر' : 'Approved This Month' }}</p>
                    <div class="w-9 h-9 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    </div>
                </div>
                <p class="stat-value">{{ $calculator->formatHours($totals['monthly_approved_minutes']) }}</p>
                <p class="text-xs text-dark-500">{{ now()->timezone(config('app.timezone', 'Asia/Amman'))->format('F Y') }}</p>
            </div>
        </div>

        {{-- UPCOMING SHIFTS --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                    <h3 class="font-semibold text-white text-sm">{{ app()->getLocale() === 'ar' ? 'مناوباتي القادمة' : 'My Upcoming Shifts' }}</h3>
                </div>
                <a href="{{ route('employee.schedules.index') }}" class="text-xs text-brand-400 hover:text-brand-300">
                    {{ app()->getLocale() === 'ar' ? 'عرض الجدول كاملاً ←' : 'View all schedules →' }}
                </a>
            </div>
            <div class="card-body">
                @if ($upcomingShifts->isEmpty())
                    <div class="flex flex-col items-center py-6 text-dark-500">
                        <svg class="w-10 h-10 mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                        <p class="text-sm">{{ app()->getLocale() === 'ar' ? 'لا توجد مناوبات قادمة مجدولة ومعتمدة حالياً.' : 'No upcoming published shifts.' }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($upcomingShifts as $shift)
                            <div class="flex items-center justify-between p-3 rounded-xl bg-dark-700/40 border border-dark-700/60 hover:border-dark-600 transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 rounded-xl bg-brand-500/10 border border-brand-500/20 flex flex-col items-center justify-center">
                                        <span class="text-sm font-bold text-brand-400 leading-none">{{ $shift->work_date->format('d') }}</span>
                                        <span class="text-[10px] text-brand-500/80 uppercase font-semibold leading-tight">{{ $shift->work_date->format('M') }}</span>
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-white">{{ $shift->work_date->translatedFormat('l') }}</p>
                                        <p class="text-xs text-dark-300 font-mono">
                                            {{ $shift->starts_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}
                                            – {{ $shift->ends_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}
                                        </p>
                                    </div>
                                </div>
                                <span class="badge bg-brand-500/10 text-brand-400 border border-brand-500/20 text-xs">
                                    {{ $shift->branch->name }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- RECENT ATTENDANCE RECORDS --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                    <h3 class="font-semibold text-white text-sm">{{ app()->getLocale() === 'ar' ? 'سجل حضوري الأخير' : 'My Recent Attendance Records' }}</h3>
                </div>
            </div>
            <div class="card-body">
                @include('partials.timesheet-table', ['entries' => $entries, 'admin' => false])
            </div>
        </div>
    </div>
</x-app-layout>
