@if ($entries->isEmpty())
    <div class="flex flex-col items-center py-8 text-dark-500">
        <svg class="w-10 h-10 mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
        </svg>
        <p class="text-sm">{{ app()->getLocale() === 'ar' ? 'لا توجد سجلات حضور لعرضها.' : 'No entries to display.' }}</p>
    </div>
@else
    <div class="overflow-x-auto -mx-6">
        <table class="table-auto-responsive w-full text-sm">
            <thead>
                <tr class="border-b border-dark-700 text-dark-400 text-xs">
                    @if ($admin ?? false)<th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'الموظف' : 'Employee' }}</th>@endif
                    <th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'التاريخ' : 'Date' }}</th>
                    <th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'الفرع' : 'Branch' }}</th>
                    <th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'وقت الحضور' : 'Check In' }}</th>
                    <th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'وقت الانصراف' : 'Check Out' }}</th>
                    <th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'المدة' : 'Hours' }}</th>
                    <th class="text-start py-3 px-4">{{ app()->getLocale() === 'ar' ? 'الحالة' : 'Status' }}</th>
                    <th class="text-end py-3 px-4"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-700/60">
                @foreach ($entries as $entry)
                    <tr class="hover:bg-dark-700/30 transition-colors">
                        @if ($admin ?? false)
                            <td class="py-3 px-4 font-medium text-white">
                                <a href="{{ route('admin.employees.show', $entry->user) }}" class="hover:text-brand-400 transition-colors">
                                    {{ $entry->user->name }}
                                </a>
                            </td>
                        @endif
                        <td class="py-3 px-4 text-dark-200">{{ $entry->work_date->format('Y-m-d') }}</td>
                        <td class="py-3 px-4">
                            <span class="badge bg-brand-500/10 text-brand-400 border border-brand-500/20 text-xs">
                                {{ $entry->branch->name }}
                            </span>
                        </td>
                        <td class="py-3 px-4 text-dark-200 font-mono text-xs">
                            {{ $entry->starts_at?->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') ?? '—' }}
                            @if ($entry->is_late)
                                <span class="badge bg-amber-500/10 text-amber-400 border border-amber-500/30 text-[10px] ml-1">
                                    {{ app()->getLocale() === 'ar' ? 'متأخر' : 'Late' }} (+{{ $entry->late_minutes }}m)
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4 text-dark-200 font-mono text-xs">
                            @if($entry->ends_at)
                                {{ $entry->ends_at->timezone(config('app.timezone', 'Asia/Amman'))->format('g:i A') }}
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 animate-pulse">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                    {{ app()->getLocale() === 'ar' ? 'بالعمل الآن' : 'Working Now' }}
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4 font-mono font-medium text-white">
                            @if($entry->ends_at)
                                {{ sprintf('%d:%02d', intdiv($entry->total_minutes, 60), $entry->total_minutes % 60) }}
                            @else
                                <span class="text-dark-400 text-xs italic">{{ app()->getLocale() === 'ar' ? 'جارٍ احتسابه...' : 'In progress...' }}</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            @if($entry->status === \App\Enums\TimesheetStatus::ClockedIn)
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                                    {{ app()->getLocale() === 'ar' ? 'حاضر (بالعمل)' : 'Clocked In' }}
                                </span>
                            @else
                                <x-status-badge :status="$entry->status" />
                            @endif
                        </td>
                        <td class="py-3 px-4 text-end">
                            @if ($admin ?? false)
                                <a href="{{ route('admin.timesheets.show', $entry) }}"
                                   class="text-xs text-brand-400 hover:text-brand-300 font-medium transition-colors">
                                    {{ app()->getLocale() === 'ar' ? 'مراجعة ←' : 'Review →' }}
                                </a>
                            @elseif($entry->status === \App\Enums\TimesheetStatus::Rejected)
                                <a href="{{ route('employee.timesheets.edit', $entry) }}"
                                   class="text-xs text-amber-400 hover:text-amber-300 font-medium transition-colors">
                                    {{ app()->getLocale() === 'ar' ? 'تعديل ←' : 'Correct →' }}
                                </a>
                            @endif
                        </td>
                    </tr>
                    @if (!($admin ?? false) && $entry->status === \App\Enums\TimesheetStatus::Rejected && $entry->rejection_reason)
                        <tr class="!bg-red-500/5 !border-red-500/10">
                            <td colspan="7" class="pb-3 px-4">
                                <div class="flex items-start gap-2 text-xs text-red-400">
                                    <svg class="w-3.5 h-3.5 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                                    <span><strong>{{ app()->getLocale() === 'ar' ? 'سبب الرفض:' : 'Rejection reason:' }}</strong> {{ $entry->rejection_reason }}</span>
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
@endif
