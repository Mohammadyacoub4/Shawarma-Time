<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Correct rejected entry</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
        <x-flash-messages />
        @if ($entry->rejection_reason)
            <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg text-sm">
                <strong>Rejection reason:</strong> {{ $entry->rejection_reason }}
            </div>
        @endif
        @include('employee.timesheets._form', [
            'action' => route('employee.timesheets.update', $entry),
            'method' => 'PUT',
            'entry' => $entry,
            'submitLabel' => 'Resubmit for approval',
        ])
    </div>
</x-app-layout>
