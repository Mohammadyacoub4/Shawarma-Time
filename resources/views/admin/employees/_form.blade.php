@php
    $isEdit = (bool) $employee;
    $selectedBranches = old('branch_ids', $employee?->branches->pluck('id')->all() ?? []);
@endphp

<form method="POST" action="{{ $isEdit ? route('admin.employees.update', $employee) : route('admin.employees.store') }}" class="space-y-5">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <x-input-label for="name" value="Full Name" />
            <x-text-input id="name" name="name" :value="old('name', $employee?->name)" required placeholder="Jane Doe" />
            <x-input-error :messages="$errors->get('name')" />
        </div>

        <div>
            <x-input-label for="email" value="Email Address" />
            <x-text-input id="email" type="email" name="email" :value="old('email', $employee?->email)" required placeholder="jane@example.com" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="phone" value="Phone (optional)" />
            <x-text-input id="phone" name="phone" :value="old('phone', $employee?->phone)" placeholder="+1 604 000 0000" />
            <x-input-error :messages="$errors->get('phone')" />
        </div>
    </div>

    <div>
        <x-input-label value="Branch Assignments" />
        <p class="text-xs text-dark-500 mb-3">Select one or both branches where this employee works.</p>
        <div class="flex flex-wrap gap-3">
            @foreach ($branches as $branch)
                <label class="flex items-center gap-3 px-4 py-3 rounded-lg bg-dark-700 border border-dark-600 hover:border-brand-500/50 cursor-pointer transition-colors has-[:checked]:border-brand-500 has-[:checked]:bg-brand-500/10">
                    <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}"
                           @checked(in_array($branch->id, $selectedBranches))
                           class="w-4 h-4 rounded border-dark-500 bg-dark-600 text-brand-500 focus:ring-brand-500 focus:ring-offset-dark-800">
                    <div>
                        <p class="text-sm font-medium text-white">{{ $branch->name }}</p>
                        <p class="text-xs text-dark-400">BC, Canada</p>
                    </div>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('branch_ids')" />
    </div>

    @unless($isEdit)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <x-input-label for="password" value="Initial Password (leave blank to auto-generate)" />
                <x-text-input id="password" type="password" name="password" placeholder="Min. 8 characters" />
                <x-input-error :messages="$errors->get('password')" />
                <p class="text-xs text-dark-500 mt-1">If blank, a secure password will be generated and shown to you.</p>
            </div>
        </div>
        <label class="flex items-center gap-3 cursor-pointer group">
            <input type="hidden" name="must_change_password" value="0">
            <input type="checkbox" name="must_change_password" value="1"
                   @checked(old('must_change_password', true))
                   class="w-4 h-4 rounded border-dark-600 bg-dark-700 text-brand-500 focus:ring-brand-500 focus:ring-offset-dark-800">
            <span class="text-sm text-dark-200 group-hover:text-white transition-colors">
                Require password change at first login
            </span>
        </label>
    @else
        <div class="p-4 rounded-lg bg-dark-700/40 border border-dark-600">
            <h4 class="text-sm font-medium text-white mb-3">Account Status</h4>
            <label class="flex items-center gap-3 cursor-pointer group">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $employee->is_active))
                       class="w-4 h-4 rounded border-dark-600 bg-dark-700 text-brand-500 focus:ring-brand-500 focus:ring-offset-dark-800">
                <div>
                    <span class="text-sm text-dark-200 group-hover:text-white transition-colors">Account active</span>
                    <p class="text-xs text-dark-500">Deactivated employees cannot sign in. Their history is preserved.</p>
                </div>
            </label>
        </div>
    @endunless

    <div class="flex items-center gap-3 pt-2 border-t border-dark-700">
        <x-primary-button>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            {{ $isEdit ? 'Save Changes' : 'Create Employee' }}
        </x-primary-button>
        <a href="{{ route('admin.employees.index') }}" class="btn-secondary">Cancel</a>
    </div>
</form>
