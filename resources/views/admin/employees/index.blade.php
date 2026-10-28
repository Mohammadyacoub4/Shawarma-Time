<x-app-layout>
    <x-slot name="header">
        <div class="page-header">
            <h1 class="page-title">Employees</h1>
            <a href="{{ route('admin.employees.create') }}" class="btn-primary">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z" /></svg>
                Add Employee
            </a>
        </div>
    </x-slot>

    <div class="space-y-5">
        <x-flash-messages />

        {{-- FILTERS --}}
        <div class="card">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.employees.index') }}" class="flex flex-wrap gap-3 items-end">
                    <div class="flex-1 min-w-[200px]">
                        <x-input-label for="q" :value="app()->getLocale() === 'ar' ? 'البحث (الاسم، البريد، الهاتف)' : 'Search by name, email, or phone'" />
                        <x-text-input id="q" name="q" value="{{ request('q') }}" placeholder="{{ app()->getLocale() === 'ar' ? 'اكتب للبحث...' : 'Type to search…' }}" />
                    </div>
                    <div class="min-w-[160px]">
                        <x-input-label for="branch_id" :value="app()->getLocale() === 'ar' ? 'الفرع' : 'Branch'" />
                        <select id="branch_id" name="branch_id" class="form-select">
                            <option value="">{{ app()->getLocale() === 'ar' ? 'جميع الفروع' : 'All branches' }}</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-[140px]">
                        <x-input-label for="status" :value="app()->getLocale() === 'ar' ? 'الحالة' : 'Status'" />
                        <select id="status" name="status" class="form-select">
                            <option value="">{{ app()->getLocale() === 'ar' ? 'جميع الحالات' : 'All statuses' }}</option>
                            <option value="active"   @selected(request('status') === 'active')>{{ app()->getLocale() === 'ar' ? 'نشط' : 'Active' }}</option>
                            <option value="inactive" @selected(request('status') === 'inactive')>{{ app()->getLocale() === 'ar' ? 'معطل' : 'Inactive' }}</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z" /></svg>
                        <span>{{ app()->getLocale() === 'ar' ? 'بحث' : 'Search' }}</span>
                    </button>
                    @if(request()->filled('q') || request()->filled('branch_id') || request()->filled('status') || request()->filled('active'))
                        <a href="{{ route('admin.employees.index') }}" class="btn-secondary">
                            {{ app()->getLocale() === 'ar' ? 'إعادة ضبط' : 'Clear' }}
                        </a>
                    @endif
                </form>
            </div>
        </div>

        {{-- TABLE --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <h3 class="font-semibold text-white text-sm">All Employees</h3>
                <span class="text-xs text-dark-400">{{ $employees->total() }} {{ Str::plural('employee', $employees->total()) }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="table-auto-responsive">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Branches</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($employees as $employee)
                            <tr>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-brand-500/15 border border-brand-500/20 flex items-center justify-center text-brand-400 text-xs font-bold shrink-0">
                                            {{ substr($employee->name, 0, 1) }}
                                        </div>
                                        <a href="{{ route('admin.employees.show', $employee) }}" class="font-medium text-white hover:text-brand-400 transition-colors">
                                            {{ $employee->name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="text-dark-400">{{ $employee->email }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($employee->branches as $branch)
                                            <span class="badge {{ $branch->name === 'Vancouver' ? 'bg-blue-500/10 text-blue-400' : 'bg-purple-500/10 text-purple-400' }}">
                                                {{ $branch->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>
                                    @if($employee->is_active)
                                        <span class="badge bg-green-500/15 text-green-400 ring-1 ring-green-500/25">{{ app()->getLocale() === 'ar' ? 'نشط' : 'Active' }}</span>
                                    @else
                                        <span class="badge bg-dark-600/50 text-dark-400 ring-1 ring-dark-500/30">{{ app()->getLocale() === 'ar' ? 'غير نشط' : 'Inactive' }}</span>
                                    @endif
                                </td>
                                <td class="text-dark-500 text-xs">{{ $employee->created_at->format('M j, Y') }}</td>
                                <td class="text-end">
                                    <div class="flex items-center justify-end gap-2.5">
                                        <a href="{{ route('admin.employees.show', $employee) }}"
                                           class="text-xs text-brand-400 hover:text-brand-300 font-medium transition-colors">
                                            {{ app()->getLocale() === 'ar' ? 'الملف' : 'Profile' }}
                                        </a>
                                        <a href="{{ route('admin.employees.edit', $employee) }}"
                                           class="text-xs text-dark-300 hover:text-white font-medium transition-colors">
                                            {{ app()->getLocale() === 'ar' ? 'تعديل' : 'Edit' }}
                                        </a>
                                        <form action="{{ route('admin.employees.toggle-status', $employee) }}" method="POST" class="inline"
                                              onsubmit="return confirm('{{ $employee->is_active ? (app()->getLocale() === 'ar' ? 'تعطيل الحساب؟' : 'Deactivate?') : (app()->getLocale() === 'ar' ? 'تفعيل الحساب؟' : 'Activate?') }}');">
                                            @csrf
                                            <button type="submit" class="text-xs {{ $employee->is_active ? 'text-amber-500/80 hover:text-amber-400' : 'text-emerald-400 hover:text-emerald-300' }} font-medium transition-colors">
                                                {{ $employee->is_active ? (app()->getLocale() === 'ar' ? 'تعطيل' : 'Deactivate') : (app()->getLocale() === 'ar' ? 'تفعيل' : 'Activate') }}
                                            </button>
                                        </form>
                                        <a href="{{ route('admin.employees.reset-password', $employee) }}"
                                           class="text-xs text-amber-500/80 hover:text-amber-400 font-medium transition-colors">
                                            {{ app()->getLocale() === 'ar' ? 'كلمة المرور' : 'Password' }}
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-12 text-center text-dark-500">
                                    <svg class="w-10 h-10 mx-auto mb-2 opacity-40" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                                    <p class="text-sm">{{ app()->getLocale() === 'ar' ? 'لم يتم العثور على أي موظف يطابق معايير البحث.' : 'No employees found matching the filter criteria.' }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($employees->hasPages())
                <div class="px-6 py-4 border-t border-dark-700">
                    {{ $employees->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
