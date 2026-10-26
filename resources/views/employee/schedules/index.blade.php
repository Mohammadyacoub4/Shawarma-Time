<x-app-layout>
    <x-slot name="header">
        <div class="page-header flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="page-title flex items-center gap-2">
                    <svg class="w-6 h-6 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5" />
                    </svg>
                    {{ app()->getLocale() === 'ar' ? 'تقويم ومناوبات العمل الأسبوعية' : 'Published Schedules' }}
                </h1>
                <p class="text-xs text-dark-400 mt-1">
                    {{ app()->getLocale() === 'ar' ? 'استعراض جدول ومناوبات العمل المعتمدة رسمياً لهذا الأسبوع' : 'View weekly published shifts and your work schedule' }}
                </p>
            </div>

            @if ($branch)
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-brand-500/10 text-brand-300 border border-brand-500/25">
                        <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ app()->getLocale() === 'ar' ? 'مناوباتك هذا الأسبوع:' : 'Your shifts this week:' }}
                        <strong class="text-white font-mono text-sm">{{ $myShiftCount }}</strong>
                    </span>
                </div>
            @endif
        </div>
    </x-slot>

    <div class="space-y-6">
        <x-flash-messages />

        @if (! $branch)
            <div class="card p-12 text-center">
                <svg class="w-12 h-12 mx-auto text-dark-500 mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
                <h3 class="text-base font-semibold text-white mb-1">{{ app()->getLocale() === 'ar' ? 'لا توجد فروع متوفرة حالياً' : 'No branches found' }}</h3>
                <p class="text-xs text-dark-400">{{ app()->getLocale() === 'ar' ? 'يرجى التواصل مع إدارة المطعم لتعيينك في أحد الفروع.' : 'Please contact the administrator to assign you to a branch.' }}</p>
            </div>
        @else
            {{-- TOOLBAR: BRANCH & WEEK NAVIGATION --}}
            <div class="card p-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <form method="GET" id="employeeScheduleForm" class="flex flex-wrap items-center gap-3">
                        @if ($branches->count() > 1)
                            <div>
                                <select name="branch_id" id="branch_id" class="form-select text-xs py-1.5 pr-8 bg-dark-700 border-dark-600 rounded-lg text-white" onchange="document.getElementById('employeeScheduleForm').submit();">
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        {{-- Week Arrows --}}
                        <div class="flex items-center gap-1 bg-dark-700/60 p-1 rounded-lg border border-dark-600">
                            <a href="{{ route('employee.schedules.index', ['branch_id' => $branch->id, 'week' => $prevWeek]) }}"
                               class="p-1.5 rounded hover:bg-dark-600 text-dark-300 hover:text-white transition-colors"
                               title="{{ app()->getLocale() === 'ar' ? 'الأسبوع السابق' : 'Previous Week' }}">
                                <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                                </svg>
                            </a>

                            <div class="px-3 py-1 text-xs font-semibold text-white flex items-center gap-2">
                                <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5" />
                                </svg>
                                <span>{{ \Carbon\Carbon::parse($weekStart)->translatedFormat('j M') }} – {{ \Carbon\Carbon::parse($weekEnd)->translatedFormat('j M Y') }}</span>
                            </div>

                            <a href="{{ route('employee.schedules.index', ['branch_id' => $branch->id, 'week' => $nextWeek]) }}"
                               class="p-1.5 rounded hover:bg-dark-600 text-dark-300 hover:text-white transition-colors"
                               title="{{ app()->getLocale() === 'ar' ? 'الأسبوع التالي' : 'Next Week' }}">
                                <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </a>
                        </div>

                        @if (! $isCurrentWeek)
                            <a href="{{ route('employee.schedules.index', ['branch_id' => $branch->id]) }}"
                               class="btn-secondary text-xs !py-1.5">
                                {{ app()->getLocale() === 'ar' ? 'هذا الأسبوع' : 'Current Week' }}
                            </a>
                        @endif

                        <input type="date" name="week" value="{{ $weekStart }}" class="text-xs py-1 px-2.5 bg-dark-700 border-dark-600 rounded-lg text-white" onchange="document.getElementById('employeeScheduleForm').submit();" />
                    </form>

                    {{-- Schedule Status Badge --}}
                    <div>
                        @if ($scheduleWeek?->is_published)
                            <span class="badge bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-xs px-3 py-1 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                {{ app()->getLocale() === 'ar' ? 'جدول معتمد ورسمي' : 'Official Published Schedule' }}
                            </span>
                        @else
                            <span class="badge bg-amber-500/15 text-amber-300 border border-amber-500/30 text-xs px-3 py-1 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                                {{ app()->getLocale() === 'ar' ? 'قيد الإعداد — لم يُعتمد بعد' : 'Draft — not yet published' }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- 7-DAY CALENDAR GRID --}}
            <div class="overflow-x-auto pb-4">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-7 gap-3 min-w-[1000px] lg:min-w-0">
                    @foreach ($days as $day)
                        @php
                            $dayShifts = $shiftsByDay->get($day, collect());
                            $carbonDay = \Carbon\Carbon::parse($day, App\Support\BranchTime::timezoneFor($branch));
                            $isToday = $day === now(App\Support\BranchTime::timezoneFor($branch))->toDateString();
                            $myShiftOnThisDay = $dayShifts->firstWhere('user_id', auth()->id());
                        @endphp
                        <div class="card flex flex-col h-full {{ $isToday ? 'border-brand-500/60 ring-1 ring-brand-500/30 bg-brand-500/[0.02]' : 'border-dark-700' }} {{ $myShiftOnThisDay ? 'ring-1 ring-emerald-500/30' : '' }}">
                            {{-- Day Header --}}
                            <div class="p-3 border-b border-dark-700/80 flex items-center justify-between gap-1 {{ $isToday ? 'bg-brand-500/10' : 'bg-dark-800/60' }}">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-sm text-white">{{ $carbonDay->translatedFormat('l') }}</span>
                                        @if ($isToday)
                                            <span class="badge bg-brand-500 text-dark-950 font-bold text-[9px] px-1 py-0.5 rounded">
                                                {{ app()->getLocale() === 'ar' ? 'اليوم' : 'Today' }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-dark-400 font-mono mt-0.5">
                                        {{ $carbonDay->format('j M') }}
                                    </p>
                                </div>

                                @if ($myShiftOnThisDay)
                                    <span class="badge bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-[10px] font-bold px-1.5 py-0.5">
                                        ★ {{ app()->getLocale() === 'ar' ? 'دوامك' : 'Yours' }}
                                    </span>
                                @endif
                            </div>

                            {{-- Shifts Content --}}
                            <div class="p-2.5 flex-1 space-y-2 min-h-[220px]">
                                @if (! $scheduleWeek?->is_published || $dayShifts->isEmpty())
                                    <div class="h-28 flex flex-col items-center justify-center border-2 border-dashed border-dark-700 rounded-lg p-2 text-center text-dark-500">
                                        <p class="text-xs">No published shifts</p>
                                        <span class="text-[11px] text-dark-600 mt-1">
                                            {{ app()->getLocale() === 'ar' ? 'لا توجد مناوبات منشورة' : 'No shifts planned' }}
                                        </span>
                                    </div>
                                @else
                                    @foreach ($dayShifts as $shift)
                                        @php
                                            $isMe = $shift->user_id === auth()->id();
                                            $shiftTz = $branch->timezone ?? 'Asia/Amman';
                                            $startsFormatted = $shift->starts_at->timezone($shiftTz)->format('g:i A');
                                            $endsFormatted = $shift->ends_at->timezone($shiftTz)->format('g:i A');
                                            $isOvernight = $shift->starts_at->timezone($shiftTz)->format('d') !== $shift->ends_at->timezone($shiftTz)->format('d');
                                        @endphp
                                        <div class="p-2.5 rounded-lg border transition-all {{ $isMe ? 'bg-emerald-500/10 border-emerald-500/40 ring-1 ring-emerald-500/30' : 'bg-dark-700/50 border-dark-600' }}">
                                            <div class="flex items-center justify-between gap-1 mb-1">
                                                <div class="flex items-center gap-1.5 overflow-hidden">
                                                    <div class="w-5 h-5 rounded-full {{ $isMe ? 'bg-emerald-500 text-dark-950 font-bold' : 'bg-dark-600 text-dark-300' }} text-[10px] flex items-center justify-center shrink-0">
                                                        {{ substr($shift->user->name, 0, 1) }}
                                                    </div>
                                                    <span class="text-xs font-semibold {{ $isMe ? 'text-emerald-300 font-bold' : 'text-white' }} truncate">
                                                        {{ $isMe ? 'You' : $shift->user->name }}
                                                        @if ($isMe)
                                                            <span class="text-[11px] text-emerald-400 font-normal">({{ app()->getLocale() === 'ar' ? 'أنت' : $shift->user->name }})</span>
                                                        @endif
                                                    </span>
                                                </div>

                                                @if ($isMe)
                                                    <span class="badge bg-emerald-500/20 text-emerald-400 text-[9px] font-bold">
                                                        {{ app()->getLocale() === 'ar' ? 'مناوبتك' : 'Your shift' }}
                                                    </span>
                                                @endif
                                            </div>

                                            <div class="text-[11px] font-mono text-dark-300 flex items-center justify-between mt-1.5">
                                                <span>{{ $startsFormatted }} – {{ $endsFormatted }}</span>
                                                @if ($isOvernight)
                                                    <span class="text-[10px] font-sans text-amber-400">{{ app()->getLocale() === 'ar' ? 'ليلي' : 'Overnight' }}</span>
                                                @endif
                                            </div>

                                            @if ($shift->notes)
                                                <p class="text-[10px] text-dark-400 mt-1 border-t border-dark-600/50 pt-1 italic truncate" title="{{ $shift->notes }}">
                                                    {{ $shift->notes }}
                                                </p>
                                            @endif
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
