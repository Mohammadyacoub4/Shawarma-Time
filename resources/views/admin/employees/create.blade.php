<x-app-layout>
    <x-slot name="header">
        <div class="page-header">
            <div>
                <div class="flex items-center gap-2 text-sm text-dark-400 mb-1">
                    <a href="{{ route('admin.employees.index') }}" class="hover:text-white transition-colors">Employees</a>
                    <span>/</span>
                    <span>New Employee</span>
                </div>
                <h1 class="page-title">Add New Employee</h1>
            </div>
        </div>
    </x-slot>

    <div class="max-w-3xl space-y-5">
        <x-flash-messages />
        <div class="card card-body">
            @include('admin.employees._form', ['employee' => null])
        </div>
    </div>
</x-app-layout>
