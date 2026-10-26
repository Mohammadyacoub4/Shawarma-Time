<x-app-layout>
    <x-slot name="header">
        <div class="page-header">
            <div>
                <div class="flex items-center gap-2 text-sm text-dark-400 mb-1">
                    <a href="{{ route('admin.timesheets.index') }}" class="hover:text-white transition-colors">Timesheets</a>
                    <span>/</span>
                    <span>Review</span>
                </div>
                <h1 class="page-title">Review Timesheet Entry</h1>
            </div>
        </div>
    </x-slot>

    <div class="max-w-5xl space-y-5">
        <x-flash-messages />

        {{-- ENTRY OVERVIEW --}}
        <div class="card">
            <div class="card-header flex items-center gap-2">
                <svg class="w-4 h-4 text-dark-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
                <h3 class="font-semibold text-white text-sm">Entry Details</h3>
                <x-status-badge :status="$entry->status" />
            </div>
            <div class="card-body">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-dark-400 text-xs mb-1">Employee</p>
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-full bg-brand-500/15 flex items-center justify-center text-brand-400 text-xs font-bold">
                                {{ substr($entry->user->name, 0, 1) }}
                            </div>
                            <p class="font-medium text-white">{{ $entry->user->name }}</p>
                        </div>
                    </div>
                    <div>
                        <p class="text-dark-400 text-xs mb-1">Branch</p>
                        <span class="badge {{ $entry->branch->name === 'Vancouver' ? 'bg-blue-500/10 text-blue-400' : 'bg-purple-500/10 text-purple-400' }}">
                            {{ $entry->branch->name }}
                        </span>
                    </div>
                    <div>
                        <p class="text-dark-400 text-xs mb-1">Work Date</p>
                        <p class="font-medium text-white">{{ $entry->work_date->format('D, M j, Y') }}</p>
                    </div>
                    <div>
                        <p class="text-dark-400 text-xs mb-1">Hours Worked</p>
                        <p class="font-mono font-bold text-white text-lg">{{ sprintf('%d:%02d', intdiv($entry->total_minutes, 60), $entry->total_minutes % 60) }}</p>
                    </div>
                </div>

                @if ($entry->shift_id === null)
                    <div class="mt-4 flex items-center gap-2 text-sm text-amber-400 bg-amber-500/10 border border-amber-500/20 px-3 py-2 rounded-lg">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                        <span><strong>No linked scheduled shift</strong> — this is unscheduled work submitted by the employee.</span>
                    </div>
                @endif

                @if ($entry->rejection_reason)
                    <div class="mt-4 flex items-start gap-2 text-sm text-red-400 bg-red-500/10 border border-red-500/20 px-3 py-2 rounded-lg">
                        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span><strong>Rejection reason:</strong> {{ $entry->rejection_reason }}</span>
                    </div>
                @endif

                @if ($entry->employee_notes)
                    <div class="mt-4">
                        <p class="text-xs text-dark-400 mb-1">Employee notes</p>
                        <p class="text-sm text-dark-200 bg-dark-700/40 rounded-lg p-3">{{ $entry->employee_notes }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- EDIT + APPROVE/REJECT --}}
        <div class="grid md:grid-cols-2 gap-5">
            {{-- EDIT FORM --}}
            <div class="card">
                <div class="card-header flex items-center gap-2">
                    <svg class="w-4 h-4 text-dark-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                    <h3 class="font-semibold text-white text-sm">Edit Entry</h3>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.timesheets.update', $entry) }}" class="space-y-4">
                        @csrf @method('PUT')
                        <input type="hidden" name="branch_id" value="{{ $entry->branch_id }}">

                        <div>
                            <x-input-label for="work_date" value="Work Date" />
                            <x-text-input type="date" name="work_date" id="work_date" :value="$entry->work_date->toDateString()" required />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <x-input-label for="start_time" value="Start Time" />
                                <x-text-input type="time" step="900" name="start_time" id="start_time" :value="$entry->starts_at->timezone('America/Vancouver')->format('H:i')" required />
                            </div>
                            <div>
                                <x-input-label for="end_time" value="End Time" />
                                <x-text-input type="time" step="900" name="end_time" id="end_time" :value="$entry->ends_at->timezone('America/Vancouver')->format('H:i')" required />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="admin_notes" value="Admin notes (optional)" />
                            <textarea name="admin_notes" id="admin_notes" rows="2"
                                      class="form-input resize-none">{{ old('admin_notes', $entry->admin_notes ?? '') }}</textarea>
                        </div>

                        <x-primary-button class="w-full justify-center">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            Save Changes
                        </x-primary-button>
                        <p class="text-xs text-dark-500 text-center">Substantive edits to approved entries return them to Pending Approval.</p>
                    </form>
                </div>
            </div>

            {{-- APPROVE / REJECT --}}
            <div class="space-y-4">
                @if ($entry->status === \App\Enums\TimesheetStatus::PendingApproval)
                    <div class="card">
                        <div class="card-body space-y-4">
                            <h3 class="font-semibold text-white text-sm">Decision</h3>

                            <form method="POST" action="{{ route('admin.timesheets.approve', $entry) }}">
                                @csrf
                                <button type="submit"
                                        class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold text-sm rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    Approve Entry
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.timesheets.reject', $entry) }}" class="space-y-3">
                                @csrf
                                <div>
                                    <x-input-label for="rejection_reason" value="Rejection reason (required)" />
                                    <textarea id="rejection_reason" name="rejection_reason" required
                                              class="form-input resize-none" rows="3"
                                              placeholder="Explain why this entry is being rejected…">{{ old('rejection_reason') }}</textarea>
                                    <x-input-error :messages="$errors->get('rejection_reason')" />
                                </div>
                                <button type="submit"
                                        class="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold text-sm rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-red-500">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    Reject Entry
                                </button>
                            </form>
                        </div>
                    </div>
                @elseif($entry->status === \App\Enums\TimesheetStatus::Approved)
                    <div class="card card-body">
                        <div class="flex items-center gap-3 text-green-400">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <div>
                                <p class="font-semibold">Entry Approved</p>
                                <p class="text-xs text-dark-400">This entry is included in approved hours totals.</p>
                            </div>
                        </div>
                    </div>
                @elseif($entry->status === \App\Enums\TimesheetStatus::Rejected)
                    <div class="card card-body">
                        <div class="flex items-center gap-3 text-red-400">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            <div>
                                <p class="font-semibold">Entry Rejected</p>
                                <p class="text-xs text-dark-400">Employee may correct and resubmit.</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- AUDIT HISTORY --}}
        <div class="card">
            <div class="card-header flex items-center gap-2">
                <svg class="w-4 h-4 text-dark-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <h3 class="font-semibold text-white text-sm">Audit History</h3>
            </div>
            <div class="card-body">
                @forelse ($entry->auditLogs as $log)
                    <div class="flex items-start gap-3 {{ !$loop->last ? 'pb-3 mb-3 border-b border-dark-700' : '' }}">
                        <div class="w-7 h-7 rounded-full bg-dark-700 border border-dark-600 flex items-center justify-center text-dark-400 shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-white">{{ $log->action }}</span>
                                <span class="text-xs text-dark-400">by {{ $log->performer->name }}</span>
                                <span class="text-xs text-dark-500">{{ $log->created_at->timezone('America/Vancouver')->format('M j, Y g:i A') }}</span>
                            </div>
                            @if($log->notes)
                                <p class="text-xs text-dark-400 mt-0.5">{{ $log->notes }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-dark-500">No audit events yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
