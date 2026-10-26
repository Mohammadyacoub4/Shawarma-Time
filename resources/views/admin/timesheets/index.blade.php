<x-app-layout>
    <x-slot name="header">
        <div class="page-header">
            <h1 class="page-title">Timesheet Approvals</h1>
        </div>
    </x-slot>

    <div class="space-y-5">
        <x-flash-messages />

        {{-- FILTERS --}}
        <div class="card card-body">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div>
                    <x-input-label for="status" value="Status" />
                    <select name="status" id="status" class="form-select min-w-[180px]">
                        <option value="pending_approval" @selected(request('status', 'pending_approval') === 'pending_approval')>Pending Approval</option>
                        <option value="approved"         @selected(request('status') === 'approved')>Approved</option>
                        <option value="rejected"         @selected(request('status') === 'rejected')>Rejected</option>
                        <option value=""                 @selected(request('status') === '')>All statuses</option>
                    </select>
                </div>
                <div>
                    <x-input-label for="branch_id" value="Branch" />
                    <select name="branch_id" id="branch_id" class="form-select min-w-[150px]">
                        <option value="">All branches</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="user_id" value="Employee" />
                    <select name="user_id" id="user_id" class="form-select min-w-[180px]">
                        <option value="">All employees</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected(request('user_id') == $employee->id)>{{ $employee->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="from_date" value="From Date" />
                    <x-text-input type="date" name="from_date" id="from_date" value="{{ request('from_date') }}" />
                </div>
                <div>
                    <x-input-label for="to_date" value="To Date" />
                    <x-text-input type="date" name="to_date" id="to_date" value="{{ request('to_date') }}" />
                </div>
                <x-primary-button>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z" /></svg>
                    Filter
                </x-primary-button>
                @if(request()->hasAny(['status', 'branch_id', 'user_id', 'from_date', 'to_date']))
                    <a href="{{ route('admin.timesheets.index') }}" class="btn-secondary">Clear</a>
                @endif
            </form>
        </div>

        {{-- RESULTS --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h3 class="font-semibold text-white text-sm">Timesheet Entries</h3>
                    @if(request('status', 'pending_approval') === 'pending_approval')
                        <span class="badge-pending">{{ $entries->total() }} pending</span>
                    @else
                        <span class="text-xs text-dark-400">{{ $entries->total() }} {{ Str::plural('entry', $entries->total()) }}</span>
                    @endif
                </div>
            </div>
            <div class="card-body">
                @include('partials.timesheet-table', ['entries' => $entries, 'admin' => true])
            </div>
            @if($entries->hasPages())
                <div class="px-6 py-4 border-t border-dark-700">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
