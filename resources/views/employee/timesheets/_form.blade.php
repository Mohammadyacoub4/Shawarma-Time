<form method="POST" action="{{ $action }}" class="bg-white p-6 rounded-lg border space-y-4">
    @csrf
    @if ($method ?? false) @method($method) @endif

    <div>
        <x-input-label for="branch_id" value="Branch where you worked" />
        <select name="branch_id" id="branch_id" class="mt-1 w-full rounded-md border-gray-300" required>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected(old('branch_id', $entry?->branch_id) == $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>

    @isset($shifts)
        <div>
            <x-input-label for="shift_id" value="Related scheduled shift (optional)" />
            <select name="shift_id" id="shift_id" class="mt-1 w-full rounded-md border-gray-300">
                <option value="">Unscheduled / not listed</option>
                @foreach ($shifts as $shift)
                    <option value="{{ $shift->id }}" @selected(old('shift_id', $entry?->shift_id) == $shift->id)>
                        {{ $shift->work_date->format('M j') }} · {{ $shift->branch->name }} · {{ $shift->starts_at->timezone('America/Vancouver')->format('g:i A') }}
                    </option>
                @endforeach
            </select>
        </div>
    @endisset

    <div>
        <x-input-label for="work_date" value="Work date (local branch date)" />
        <x-text-input type="date" name="work_date" id="work_date" class="mt-1 w-full" :value="old('work_date', optional($entry?->work_date)->toDateString())" required />
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <x-input-label for="start_time" value="Actual start time" />
            <x-text-input type="time" step="900" name="start_time" id="start_time" class="mt-1 w-full" :value="old('start_time', isset($entry) ? $entry->starts_at->timezone('America/Vancouver')->format('H:i') : '')" required />
        </div>
        <div>
            <x-input-label for="end_time" value="Actual end time" />
            <x-text-input type="time" step="900" name="end_time" id="end_time" class="mt-1 w-full" :value="old('end_time', isset($entry) ? $entry->ends_at->timezone('America/Vancouver')->format('H:i') : '')" required />
        </div>
    </div>

    <div>
        <x-input-label for="employee_notes" value="Notes (optional)" />
        <textarea name="employee_notes" id="employee_notes" rows="3" class="mt-1 w-full rounded-md border-gray-300">{{ old('employee_notes', $entry?->employee_notes) }}</textarea>
    </div>

    <p class="text-xs text-gray-500">Enter start and end times in 15-minute increments. Total hours are calculated on the server. Overnight shifts are supported.</p>

    <x-primary-button>{{ $submitLabel }}</x-primary-button>
</form>
