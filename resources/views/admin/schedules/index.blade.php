<x-app-layout>
    <x-slot name="header">
        <div class="page-header flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="page-title flex items-center gap-2">
                    <svg class="w-6 h-6 text-brand-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5" />
                    </svg>
                    {{ app()->getLocale() === 'ar' ? 'تقويم المناوبات والجدول الأسبوعي' : 'Weekly Schedule Calendar' }}
                </h1>
                <p class="text-xs text-dark-400 mt-1">
                    {{ app()->getLocale() === 'ar' ? 'تخطيط شفتات الموظفين للأسبوع بالكامل، إدارة فترات السماح، واعتماد ونشر الجدول لجميع الموظفين' : 'Manage weekly employee shifts, grace periods, and publish the schedule to all employees' }}
                </p>
            </div>

            @if ($branch)
                <div class="flex flex-wrap items-center gap-2">
                    {{-- PUBLISH / UNPUBLISH STATUS & BUTTON --}}
                    @if ($scheduleWeek?->is_published)
                        <div class="flex items-center gap-2">
                            <span class="badge bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-xs px-3 py-1 flex items-center gap-1.5 shadow-sm">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                {{ app()->getLocale() === 'ar' ? 'الجدول منشور وظاهر لجميع الموظفين' : 'Published & Visible to Employees' }}
                            </span>
                            <form method="POST" action="{{ route('admin.schedules.unpublish') }}" class="inline"
                                  onsubmit="return confirm('{{ app()->getLocale() === 'ar' ? 'هل تريد إلغاء نشر الجدول وإعادته إلى مسودة؟ (لن يظهر للموظفين حتى تعتمده مجدداً)' : 'Unpublish this schedule? It will become a draft.' }}');">
                                @csrf
                                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                                <input type="hidden" name="week_start" value="{{ $weekStart }}">
                                <button type="submit" class="btn-secondary text-xs !py-1.5 text-amber-400 hover:text-amber-300">
                                    {{ app()->getLocale() === 'ar' ? 'إلغاء النشر (مسودة)' : 'Return to Draft' }}
                                </button>
                            </form>
                        </div>
                    @else
                        <div class="flex items-center gap-2">
                            <span class="badge bg-amber-500/15 text-amber-300 border border-amber-500/30 text-xs px-3 py-1 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                                {{ app()->getLocale() === 'ar' ? 'مسودة — غير معتمد بعد' : 'Draft — Not Published' }}
                            </span>
                            <form method="POST" action="{{ route('admin.schedules.publish') }}" class="inline"
                                  onsubmit="return confirm('{{ app()->getLocale() === 'ar' ? 'تأكيد اعتماد ونشر الجدول لجميع الموظفين الآن؟ سيصبح الجدول ظاهراً فوراً في حسابات جميع الموظفين.' : 'Publish this weekly schedule to all employees now?' }}');">
                                @csrf
                                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                                <input type="hidden" name="week_start" value="{{ $weekStart }}">
                                <button type="submit" class="btn-primary text-xs !py-1.5 bg-emerald-600 hover:bg-emerald-500 shadow-lg shadow-emerald-900/30 flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'اعتماد الجدول ونشره للجميع' : 'Publish Week to Everyone' }}
                                </button>
                            </form>
                        </div>
                    @endif
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
                <h3 class="text-base font-semibold text-white mb-1">{{ app()->getLocale() === 'ar' ? 'لا توجد فروع مسجلة' : 'No branches found' }}</h3>
                <p class="text-xs text-dark-400">{{ app()->getLocale() === 'ar' ? 'يرجى تهيئة الفروع أولاً لتتمكن من إنشاء الجداول.' : 'Please seed or create branches first.' }}</p>
            </div>
        @else
            {{-- TOP TOOLBAR: WEEK NAVIGATOR, BRANCH SELECTOR & QUICK ACTIONS --}}
            <div class="card p-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    {{-- Branch & Date Jump --}}
                    <form method="GET" id="scheduleFilterForm" class="flex flex-wrap items-center gap-3">
                        <div>
                            <select name="branch_id" id="branch_id" class="form-select text-xs py-1.5 pr-8 bg-dark-700 border-dark-600 rounded-lg text-white" onchange="document.getElementById('scheduleFilterForm').submit();">
                                @foreach ($branches as $b)
                                    <option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Week Navigation Arrows --}}
                        <div class="flex items-center gap-1 bg-dark-700/60 p-1 rounded-lg border border-dark-600">
                            <a href="{{ route('admin.schedules.index', ['branch_id' => $branch->id, 'week' => $prevWeek]) }}"
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

                            <a href="{{ route('admin.schedules.index', ['branch_id' => $branch->id, 'week' => $nextWeek]) }}"
                               class="p-1.5 rounded hover:bg-dark-600 text-dark-300 hover:text-white transition-colors"
                               title="{{ app()->getLocale() === 'ar' ? 'الأسبوع التالي' : 'Next Week' }}">
                                <svg class="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                </svg>
                            </a>
                        </div>

                        @if (! $isCurrentWeek)
                            <a href="{{ route('admin.schedules.index', ['branch_id' => $branch->id]) }}"
                               class="btn-secondary text-xs !py-1.5">
                                {{ app()->getLocale() === 'ar' ? 'هذا الأسبوع' : 'Current Week' }}
                            </a>
                        @endif

                        <input type="date" name="week" value="{{ $weekStart }}" class="text-xs py-1 px-2.5 bg-dark-700 border-dark-600 rounded-lg text-white" onchange="document.getElementById('scheduleFilterForm').submit();" />
                    </form>

                    {{-- Quick Action Buttons --}}
                    <div class="flex flex-wrap items-center gap-2">
                        {{-- 1. Batch / Full Week Assign Modal Trigger --}}
                        <button type="button" onclick="openBatchModal()" class="btn-primary text-xs !py-2 flex items-center gap-1.5 bg-brand-500 hover:bg-brand-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                            {{ app()->getLocale() === 'ar' ? 'إضافة مناوبة للأسبوع كامل' : 'Add Weekly Shift' }}
                        </button>

                        {{-- 2. Copy Previous Week --}}
                        <form method="POST" action="{{ route('admin.schedules.copy-previous') }}" class="inline"
                              onsubmit="return confirm('{{ app()->getLocale() === 'ar' ? 'هل تريد نسخ جميع مناوبات الأسبوع السابق إلى هذا الأسبوع؟' : 'Copy all shifts from last week into this week?' }}');">
                            @csrf
                            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                            <input type="hidden" name="week_start" value="{{ $weekStart }}">
                            <button type="submit" class="btn-secondary text-xs !py-2 flex items-center gap-1.5 hover:text-white" title="{{ app()->getLocale() === 'ar' ? 'نسخ مناوبات الأسبوع السابق' : 'Copy Last Week' }}">
                                <svg class="w-4 h-4 text-dark-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
                                </svg>
                                {{ app()->getLocale() === 'ar' ? 'نسخ الأسبوع السابق' : 'Copy Last Week' }}
                            </button>
                        </form>

                        {{-- 3. Clear Week --}}
                        @if ($totalShifts > 0)
                            <form method="POST" action="{{ route('admin.schedules.clear-week') }}" class="inline"
                                  onsubmit="return confirm('{{ app()->getLocale() === 'ar' ? 'تحذير: هل أنت متأكد من مسح جميع مناوبات هذا الأسبوع؟' : 'Clear all shifts for this week?' }}');">
                                @csrf
                                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                                <input type="hidden" name="week_start" value="{{ $weekStart }}">
                                <button type="submit" class="btn-secondary text-xs !py-2 text-red-400 hover:text-red-300" title="{{ app()->getLocale() === 'ar' ? 'مسح مناوبات الأسبوع' : 'Clear Week' }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'مسح الأسبوع' : 'Clear' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- Week Summary KPI Bar --}}
                <div class="mt-4 pt-3 border-t border-dark-700/60 flex flex-wrap items-center justify-between gap-3 text-xs text-dark-300">
                    <div class="flex items-center gap-4">
                        <span>
                            {{ app()->getLocale() === 'ar' ? 'إجمالي المناوبات:' : 'Total Shifts:' }}
                            <strong class="text-white font-mono">{{ $totalShifts }}</strong>
                        </span>
                        <span>
                            {{ app()->getLocale() === 'ar' ? 'إجمالي الساعات المجدولة:' : 'Total Hours:' }}
                            <strong class="text-brand-400 font-mono">{{ sprintf('%d:%02d', intdiv($totalScheduledMinutes, 60), $totalScheduledMinutes % 60) }}</strong>
                        </span>
                        <span>
                            {{ app()->getLocale() === 'ar' ? 'المنطقة الزمنية للفرع:' : 'Branch Timezone:' }}
                            <span class="badge bg-dark-700 text-dark-300 text-[11px]">{{ $branch->timezone }}</span>
                        </span>
                    </div>

                    @if ($employees->isEmpty())
                        <div class="text-amber-400 flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                            </svg>
                            <span>{{ app()->getLocale() === 'ar' ? 'لا يوجد موظفون معينون لهذا الفرع.' : 'No active employees assigned to this branch.' }}</span>
                            <a href="{{ route('admin.employees.index') }}" class="underline text-white font-semibold">{{ app()->getLocale() === 'ar' ? 'إدارة الموظفين' : 'Manage' }}</a>
                        </div>
                    @endif
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
                        @endphp
                        <div class="card flex flex-col h-full {{ $isToday ? 'border-brand-500/60 ring-1 ring-brand-500/30 bg-brand-500/[0.02]' : 'border-dark-700' }}">
                            {{-- Day Column Header --}}
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
                                        <span class="text-dark-500">({{ $dayShifts->count() }})</span>
                                    </p>
                                </div>

                                {{-- Quick Add button for this specific day --}}
                                <button type="button"
                                        onclick="openSingleModal('{{ $day }}')"
                                        class="p-1 rounded-md bg-dark-700 hover:bg-brand-500 hover:text-dark-950 text-dark-300 transition-colors"
                                        title="{{ app()->getLocale() === 'ar' ? 'إضافة مناوبة لهذا اليوم' : 'Add shift to this day' }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                </button>
                            </div>

                            {{-- Day Column Body (List of Shift Cards) --}}
                            <div class="p-2.5 flex-1 space-y-2 min-h-[300px]">
                                @forelse ($dayShifts as $shift)
                                    @php
                                        $shiftTz = $branch->timezone ?? 'Asia/Amman';
                                        $startsFmt = $shift->starts_at->timezone($shiftTz)->format('g:i A');
                                        $endsFmt = $shift->ends_at->timezone($shiftTz)->format('g:i A');
                                        $isOvernight = $shift->starts_at->timezone($shiftTz)->format('d') !== $shift->ends_at->timezone($shiftTz)->format('d');
                                        $durationMinutes = $shift->starts_at->diffInMinutes($shift->ends_at);
                                        $durationHours = sprintf('%d:%02d', intdiv($durationMinutes, 60), $durationMinutes % 60);
                                    @endphp
                                    <div class="group relative p-2.5 rounded-lg bg-dark-700/60 border border-dark-600 hover:border-brand-500/50 hover:bg-dark-700 transition-all shadow-sm">
                                        {{-- Employee Name & Avatar --}}
                                        <div class="flex items-center justify-between gap-1 mb-1.5">
                                            <div class="flex items-center gap-1.5 overflow-hidden">
                                                <div class="w-5 h-5 rounded-full bg-brand-500/20 text-brand-300 font-bold text-[10px] flex items-center justify-center shrink-0">
                                                    {{ substr($shift->user->name, 0, 1) }}
                                                </div>
                                                <span class="text-xs font-semibold text-white truncate" title="{{ $shift->user->name }}">
                                                    {{ $shift->user->name }}
                                                </span>
                                            </div>

                                            {{-- Shift Action Buttons (Edit / Delete) --}}
                                            <div class="flex items-center gap-1 opacity-80 group-hover:opacity-100 transition-opacity">
                                                <button type="button"
                                                        onclick="editShift({{ json_encode([
                                                            'id' => $shift->id,
                                                            'user_id' => $shift->user_id,
                                                            'work_date' => $shift->work_date->toDateString(),
                                                            'start_time' => $shift->starts_at->timezone($shiftTz)->format('H:i'),
                                                            'end_time' => $shift->ends_at->timezone($shiftTz)->format('H:i'),
                                                            'grace_minutes' => $shift->grace_minutes,
                                                            'notes' => $shift->notes,
                                                        ]) }})"
                                                        class="p-1 rounded text-dark-400 hover:text-brand-300 hover:bg-dark-600"
                                                        title="{{ app()->getLocale() === 'ar' ? 'تعديل المناوبة' : 'Edit' }}">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                    </svg>
                                                </button>

                                                <form method="POST" action="{{ route('admin.schedules.shifts.destroy', $shift) }}"
                                                      onsubmit="return confirm('{{ app()->getLocale() === 'ar' ? 'هل تريد بالتأكيد حذف هذه المناوبة؟' : 'Delete this shift?' }}');"
                                                      class="inline">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="p-1 rounded text-dark-400 hover:text-red-400 hover:bg-dark-600"
                                                            title="{{ app()->getLocale() === 'ar' ? 'حذف المناوبة' : 'Delete' }}">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                        </svg>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>

                                        {{-- Shift Time --}}
                                        <div class="text-[11px] font-mono font-medium text-brand-300 flex items-center justify-between">
                                            <span>{{ $startsFmt }} - {{ $endsFmt }}</span>
                                            <span class="text-dark-400 font-sans text-[10px]">({{ $durationHours }}h)</span>
                                        </div>

                                        {{-- Badges: Overnight & Grace Period --}}
                                        <div class="flex flex-wrap items-center gap-1 mt-1.5">
                                            @if ($isOvernight)
                                                <span class="badge bg-amber-500/20 text-amber-300 border border-amber-500/30 text-[9px] px-1 py-0.2">
                                                    {{ app()->getLocale() === 'ar' ? 'ليلي' : 'Overnight' }}
                                                </span>
                                            @endif
                                            @if ($shift->grace_minutes)
                                                <span class="badge bg-dark-600 text-dark-300 text-[9px] px-1 py-0.2" title="{{ app()->getLocale() === 'ar' ? 'فترة السماح بالتأخير' : 'Grace Period' }}">
                                                    {{ $shift->grace_minutes }}m {{ app()->getLocale() === 'ar' ? 'سماح' : 'grace' }}
                                                </span>
                                            @endif
                                        </div>

                                        @if ($shift->notes)
                                            <p class="text-[10px] text-dark-400 mt-1 italic truncate" title="{{ $shift->notes }}">
                                                {{ $shift->notes }}
                                            </p>
                                        @endif
                                    </div>
                                @empty
                                    <div class="h-28 flex flex-col items-center justify-center border-2 border-dashed border-dark-700 rounded-lg p-2 text-center text-dark-500">
                                        <p class="text-xs mb-1.5">{{ app()->getLocale() === 'ar' ? 'لا توجد مناوبات' : 'No shifts' }}</p>
                                        <button type="button"
                                                onclick="openSingleModal('{{ $day }}')"
                                                class="text-[11px] text-brand-400 hover:text-brand-300 font-medium transition-colors">
                                            + {{ app()->getLocale() === 'ar' ? 'إضافة مناوبة' : 'Add shift' }}
                                        </button>
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- MODAL 1: BATCH FULL-WEEK SHIFT ASSIGNMENT --}}
    <div id="batchModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm hidden p-4">
        <div class="card w-full max-w-lg bg-dark-800 border-dark-600 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="card-header flex items-center justify-between py-3 px-5 border-b border-dark-700">
                <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-brand-500/20 text-brand-400 flex items-center justify-center font-bold">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 9v7.5" />
                        </svg>
                    </div>
                    <h3 class="font-bold text-white text-base">
                        {{ app()->getLocale() === 'ar' ? 'جدولة مناوبة للموظف على مدار الأسبوع' : 'Bulk Assign Shift Across Week' }}
                    </h3>
                </div>
                <button type="button" onclick="closeBatchModal()" class="text-dark-400 hover:text-white p-1 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.schedules.batch-store') }}" class="p-5 space-y-4">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $branch?->id }}">

                {{-- Employee Select --}}
                <div>
                    <x-input-label for="batch_user_id" :value="app()->getLocale() === 'ar' ? 'الموظف' : 'Employee'" />
                    <select name="user_id" id="batch_user_id" class="form-select w-full text-xs" required>
                        <option value="">{{ app()->getLocale() === 'ar' ? 'اختر الموظف المراد جدولة دوامه…' : 'Select employee…' }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Days of Week Selection Checkboxes --}}
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <x-input-label :value="app()->getLocale() === 'ar' ? 'حدد أيام العمل لهذا الأسبوع' : 'Select Days of Week'" />
                        <div class="flex items-center gap-2 text-xs">
                            <button type="button" onclick="toggleAllDays(true)" class="text-brand-400 hover:underline">
                                {{ app()->getLocale() === 'ar' ? 'تحديد الكل' : 'All' }}
                            </button>
                            <span class="text-dark-600">|</span>
                            <button type="button" onclick="toggleWeekdays()" class="text-brand-400 hover:underline">
                                {{ app()->getLocale() === 'ar' ? 'أيام العمل فقط' : 'Workdays' }}
                            </button>
                            <span class="text-dark-600">|</span>
                            <button type="button" onclick="toggleAllDays(false)" class="text-dark-400 hover:underline">
                                {{ app()->getLocale() === 'ar' ? 'إلغاء' : 'None' }}
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 bg-dark-700/50 p-3 rounded-lg border border-dark-700">
                        @foreach ($days as $index => $day)
                            @php $dayCarbon = \Carbon\Carbon::parse($day, App\Support\BranchTime::timezoneFor($branch)); @endphp
                            <label class="flex items-center gap-2 text-xs text-dark-200 cursor-pointer p-1.5 rounded hover:bg-dark-600/50 transition-colors">
                                <input type="checkbox" name="days[]" value="{{ $day }}" class="batch-day-checkbox rounded bg-dark-700 border-dark-600 text-brand-500 focus:ring-brand-500/20" @checked($index < 5)>
                                <div>
                                    <span class="font-medium text-white block">{{ $dayCarbon->translatedFormat('D') }}</span>
                                    <span class="text-[10px] text-dark-400">{{ $dayCarbon->format('j M') }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                {{-- Shift Times --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="batch_start_time" :value="app()->getLocale() === 'ar' ? 'وقت البدء' : 'Start Time'" />
                        <x-text-input type="time" name="start_time" id="batch_start_time" value="09:00" required class="text-xs w-full" />
                    </div>
                    <div>
                        <x-input-label for="batch_end_time" :value="app()->getLocale() === 'ar' ? 'وقت الانتهاء' : 'End Time'" />
                        <x-text-input type="time" name="end_time" id="batch_end_time" value="17:00" required class="text-xs w-full" />
                    </div>
                </div>

                {{-- Grace Period & Notes --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="batch_grace_minutes" :value="app()->getLocale() === 'ar' ? 'فترة السماح بالتأخير (دقائق)' : 'Grace Period (mins)'" />
                        <x-text-input type="number" name="grace_minutes" id="batch_grace_minutes" value="{{ $branch?->grace_minutes ?? 15 }}" min="0" max="120" class="text-xs w-full" />
                    </div>
                    <div>
                        <x-input-label for="batch_notes" :value="app()->getLocale() === 'ar' ? 'ملاحظات (اختياري)' : 'Notes'" />
                        <x-text-input type="text" name="notes" id="batch_notes" placeholder="{{ app()->getLocale() === 'ar' ? 'ملاحظة للمناوبة…' : 'Optional notes' }}" class="text-xs w-full" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-dark-700">
                    <button type="button" onclick="closeBatchModal()" class="btn-secondary text-xs">
                        {{ app()->getLocale() === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <x-primary-button class="text-xs !py-2 bg-brand-500 hover:bg-brand-400">
                        {{ app()->getLocale() === 'ar' ? 'تطبيق وجدولة الأيام المحددة' : 'Apply to Selected Days' }}
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODAL 2: SINGLE SHIFT CREATE / EDIT MODAL --}}
    <div id="singleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 backdrop-blur-sm hidden p-4">
        <div class="card w-full max-w-md bg-dark-800 border-dark-600 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-150">
            <div class="card-header flex items-center justify-between py-3 px-5 border-b border-dark-700">
                <h3 id="singleModalTitle" class="font-bold text-white text-base">
                    {{ app()->getLocale() === 'ar' ? 'إضافة مناوبة جديدة' : 'Add Shift' }}
                </h3>
                <button type="button" onclick="closeSingleModal()" class="text-dark-400 hover:text-white p-1 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <form id="singleShiftForm" method="POST" action="{{ route('admin.schedules.shifts.store') }}" class="p-5 space-y-4">
                @csrf
                <div id="singleMethodField"></div>
                <input type="hidden" name="branch_id" value="{{ $branch?->id }}">

                <div>
                    <x-input-label for="single_user_id" :value="app()->getLocale() === 'ar' ? 'الموظف' : 'Employee'" />
                    <select name="user_id" id="single_user_id" class="form-select w-full text-xs" required>
                        <option value="">{{ app()->getLocale() === 'ar' ? 'اختر الموظف…' : 'Select employee…' }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <x-input-label for="single_work_date" :value="app()->getLocale() === 'ar' ? 'تاريخ العمل' : 'Work Date'" />
                    <x-text-input type="date" name="work_date" id="single_work_date" required class="text-xs w-full" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="single_start_time" :value="app()->getLocale() === 'ar' ? 'وقت البدء' : 'Start Time'" />
                        <x-text-input type="time" name="start_time" id="single_start_time" value="09:00" required class="text-xs w-full" />
                    </div>
                    <div>
                        <x-input-label for="single_end_time" :value="app()->getLocale() === 'ar' ? 'وقت الانتهاء' : 'End Time'" />
                        <x-text-input type="time" name="end_time" id="single_end_time" value="17:00" required class="text-xs w-full" />
                    </div>
                </div>

                <div>
                    <x-input-label for="single_grace_minutes" :value="app()->getLocale() === 'ar' ? 'فترة السماح بالتأخير (دقائق)' : 'Grace Period (mins)'" />
                    <x-text-input type="number" name="grace_minutes" id="single_grace_minutes" value="{{ $branch?->grace_minutes ?? 15 }}" min="0" max="120" class="text-xs w-full" />
                </div>

                <div>
                    <x-input-label for="single_notes" :value="app()->getLocale() === 'ar' ? 'ملاحظات (اختياري)' : 'Notes'" />
                    <textarea name="notes" id="single_notes" rows="2" class="form-input text-xs w-full resize-none" placeholder="{{ app()->getLocale() === 'ar' ? 'ملاحظات خاصة بالمناوبة…' : 'Shift notes' }}"></textarea>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-dark-700">
                    <button type="button" onclick="closeSingleModal()" class="btn-secondary text-xs">
                        {{ app()->getLocale() === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </button>
                    <x-primary-button id="singleSubmitBtn" class="text-xs !py-2 bg-brand-500 hover:bg-brand-400">
                        {{ app()->getLocale() === 'ar' ? 'حفظ المناوبة' : 'Save Shift' }}
                    </x-primary-button>
                </div>
            </form>
        </div>
    </div>

    {{-- INTERACTION SCRIPTS --}}
    <script>
        function openBatchModal() {
            document.getElementById('batchModal').classList.remove('hidden');
        }

        function closeBatchModal() {
            document.getElementById('batchModal').classList.add('hidden');
        }

        function toggleAllDays(check) {
            document.querySelectorAll('.batch-day-checkbox').forEach(cb => cb.checked = check);
        }

        function toggleWeekdays() {
            const checkboxes = Array.from(document.querySelectorAll('.batch-day-checkbox'));
            checkboxes.forEach((cb, idx) => {
                cb.checked = (idx < 5); // first 5 days
            });
        }

        function openSingleModal(date) {
            const form = document.getElementById('singleShiftForm');
            form.action = "{{ route('admin.schedules.shifts.store') }}";
            document.getElementById('singleMethodField').innerHTML = '';
            document.getElementById('singleModalTitle').textContent = "{{ app()->getLocale() === 'ar' ? 'إضافة مناوبة جديدة' : 'Add Shift' }}";
            document.getElementById('singleSubmitBtn').textContent = "{{ app()->getLocale() === 'ar' ? 'حفظ المناوبة' : 'Save Shift' }}";

            document.getElementById('single_user_id').value = '';
            document.getElementById('single_work_date').value = date;
            document.getElementById('single_start_time').value = '09:00';
            document.getElementById('single_end_time').value = '17:00';
            document.getElementById('single_grace_minutes').value = '{{ $branch?->grace_minutes ?? 15 }}';
            document.getElementById('single_notes').value = '';

            document.getElementById('singleModal').classList.remove('hidden');
        }

        function editShift(shift) {
            const form = document.getElementById('singleShiftForm');
            form.action = "/admin/schedules/shifts/" + shift.id;
            document.getElementById('singleMethodField').innerHTML = '<input type="hidden" name="_method" value="PUT">';
            document.getElementById('singleModalTitle').textContent = "{{ app()->getLocale() === 'ar' ? 'تعديل بيانات المناوبة' : 'Edit Shift' }}";
            document.getElementById('singleSubmitBtn').textContent = "{{ app()->getLocale() === 'ar' ? 'تحديث المناوبة' : 'Update Shift' }}";

            document.getElementById('single_user_id').value = shift.user_id;
            document.getElementById('single_work_date').value = shift.work_date;
            document.getElementById('single_start_time').value = shift.start_time;
            document.getElementById('single_end_time').value = shift.end_time;
            document.getElementById('single_grace_minutes').value = shift.grace_minutes || '15';
            document.getElementById('single_notes').value = shift.notes || '';

            document.getElementById('singleModal').classList.remove('hidden');
        }

        function closeSingleModal() {
            document.getElementById('singleModal').classList.add('hidden');
        }

        // Close on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeBatchModal();
                closeSingleModal();
            }
        });
    </script>
</x-app-layout>
