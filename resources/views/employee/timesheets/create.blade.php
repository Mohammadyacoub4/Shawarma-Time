<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">Submit worked hours</h2></x-slot>
    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <x-flash-messages />
        @include('employee.timesheets._form', [
            'action' => route('employee.timesheets.store'),
            'entry' => null,
            'shifts' => $shifts,
            'submitLabel' => 'Submit for approval',
        ])
    </div>
</x-app-layout>
