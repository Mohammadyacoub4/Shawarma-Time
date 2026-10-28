<x-app-layout>
    <x-slot name="header">
        <div class="page-header">
            <div>
                <div class="flex items-center gap-2 text-sm text-dark-400 mb-1">
                    <a href="{{ route('admin.employees.index') }}" class="hover:text-white transition-colors">
                        {{ app()->getLocale() === 'ar' ? 'الموظفون' : 'Employees' }}
                    </a>
                    <span>/</span>
                    <a href="{{ route('admin.employees.show', $employee) }}" class="hover:text-white transition-colors">
                        {{ $employee->name }}
                    </a>
                    <span>/</span>
                    <span class="text-amber-400">{{ app()->getLocale() === 'ar' ? 'تعيين كلمة المرور' : 'Reset Password' }}</span>
                </div>
                <h1 class="page-title flex items-center gap-2">
                    <svg class="w-6 h-6 text-amber-400 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span>{{ app()->getLocale() === 'ar' ? 'تعيين كلمة مرور جديدة للموظف' : 'Set New Employee Password' }}</span>
                </h1>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.employees.show', $employee) }}" class="btn-secondary text-sm">
                    {{ app()->getLocale() === 'ar' ? 'إلغاء والعودة' : 'Back to Profile' }}
                </a>
            </div>
        </div>
    </x-slot>

    <div class="max-w-2xl space-y-6">
        <x-flash-messages />

        <!-- Employee Info Card -->
        <div class="card card-body bg-dark-800/90 border border-dark-700 p-5 rounded-xl">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-brand-500/10 border border-brand-500/20 text-brand-400 font-bold text-lg flex items-center justify-center shrink-0">
                    {{ strtoupper(substr($employee->name, 0, 2)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="text-base font-semibold text-white truncate">{{ $employee->name }}</h2>
                        <span class="badge {{ $employee->is_active ? 'badge-success' : 'badge-danger' }}">
                            {{ $employee->is_active ? (app()->getLocale() === 'ar' ? 'نشط' : 'Active') : (app()->getLocale() === 'ar' ? 'معطل' : 'Inactive') }}
                        </span>
                    </div>
                    <p class="text-xs text-dark-400 truncate mt-0.5">{{ $employee->email }}</p>
                    <div class="flex items-center gap-2 mt-2">
                        @foreach($employee->branches as $branch)
                            <span class="text-[11px] px-2 py-0.5 rounded bg-dark-700 text-dark-300 border border-dark-600">
                                {{ $branch->name }}
                            </span>
                        @endforeach
                        @if($employee->phone)
                            <span class="text-[11px] px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20">
                                {{ $employee->phone }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Password Reset Form Card -->
        <div class="card card-body bg-dark-800 border border-dark-700 p-6 rounded-xl space-y-6">
            <div class="border-b border-dark-700 pb-4">
                <h3 class="text-base font-semibold text-white">
                    {{ app()->getLocale() === 'ar' ? 'تحديد كلمة المرور للدخول' : 'Set Account Password' }}
                </h3>
                <p class="text-xs text-dark-400 mt-1">
                    {{ app()->getLocale() === 'ar'
                        ? 'أدخل كلمة المرور الجديدة التي سيستخدمها الموظف لتسجيل الدخول إلى حسابه.'
                        : 'Enter the new password the employee will use to log into the portal.' }}
                </p>
            </div>

            <form method="POST" action="{{ route('admin.employees.reset-password.update', $employee) }}" class="space-y-5" id="reset-password-form">
                @csrf

                <!-- Quick Generator helper -->
                <div class="flex items-center justify-between p-3 rounded-lg bg-dark-700/60 border border-dark-600">
                    <div class="flex items-center gap-2 text-xs text-dark-300">
                        <svg class="w-4 h-4 text-brand-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span>{{ app()->getLocale() === 'ar' ? 'هل تريد كلمة مرور عشوائية مقترحة؟' : 'Need an auto-generated password?' }}</span>
                    </div>
                    <button type="button" id="btn-generate-password" class="text-xs text-brand-400 hover:text-brand-300 font-semibold px-2.5 py-1 rounded bg-brand-500/10 hover:bg-brand-500/20 border border-brand-500/30 transition-colors">
                        {{ app()->getLocale() === 'ar' ? 'توليد تلقائي' : 'Auto Generate' }}
                    </button>
                </div>

                <!-- New Password Field -->
                <div>
                    <x-input-label for="password" :value="app()->getLocale() === 'ar' ? 'كلمة المرور الجديدة' : 'New Password'" />
                    <div class="relative mt-1">
                        <input id="password"
                               type="password"
                               name="password"
                               required
                               minlength="8"
                               autocomplete="new-password"
                               placeholder="{{ app()->getLocale() === 'ar' ? '8 أحرف على الأقل (مثال: Shawarma2026!)' : 'Min. 8 characters (e.g. Shawarma2026!)' }}"
                               class="form-input w-full pr-10 bg-dark-900 border-dark-600 text-white rounded-lg focus:border-brand-500 focus:ring-brand-500" />
                        <button type="button"
                                onclick="toggleVisibility('password', this)"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-dark-400 hover:text-white"
                                title="إظهار / إخفاء">
                            <svg class="w-4 h-4 eye-open" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('password')" class="mt-1" />
                    <p class="text-xs text-dark-500 mt-1">
                        {{ app()->getLocale() === 'ar' ? 'يجب أن لا تقل عن 8 خانات' : 'Must be at least 8 characters long.' }}
                    </p>
                </div>

                <!-- Password Confirmation Field -->
                <div>
                    <x-input-label for="password_confirmation" :value="app()->getLocale() === 'ar' ? 'تأكيد كلمة المرور الجديدة' : 'Confirm New Password'" />
                    <div class="relative mt-1">
                        <input id="password_confirmation"
                               type="password"
                               name="password_confirmation"
                               required
                               minlength="8"
                               autocomplete="new-password"
                               placeholder="{{ app()->getLocale() === 'ar' ? 'أعد إدخال كلمة المرور للتأكيد' : 'Re-enter password to confirm' }}"
                               class="form-input w-full pr-10 bg-dark-900 border-dark-600 text-white rounded-lg focus:border-brand-500 focus:ring-brand-500" />
                        <button type="button"
                                onclick="toggleVisibility('password_confirmation', this)"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center text-dark-400 hover:text-white"
                                title="إظهار / إخفاء">
                            <svg class="w-4 h-4 eye-open" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('password_confirmation')" class="mt-1" />
                </div>

                <!-- Generated Password Display Box (Shows when generated) -->
                <div id="generated-info-box" class="hidden p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs">
                    <div class="flex items-center justify-between">
                        <span>{{ app()->getLocale() === 'ar' ? 'تم إنشاء كلمة مرور: ' : 'Generated password: ' }} <strong id="generated-pw-text" class="font-mono text-white text-sm select-all"></strong></span>
                        <button type="button" id="btn-copy-pw" class="text-xs bg-amber-500/20 hover:bg-amber-500/30 text-amber-200 px-2 py-0.5 rounded transition-colors">
                            {{ app()->getLocale() === 'ar' ? 'نسخ' : 'Copy' }}
                        </button>
                    </div>
                    <p class="text-[11px] text-amber-400/80 mt-1">
                        {{ app()->getLocale() === 'ar' ? 'يمكنك نسخها وإرسالها للموظف عبر رسالة أو واتساب.' : 'You can copy and send this to the employee.' }}
                    </p>
                </div>

                <!-- Force Password Change Checkbox -->
                <div class="pt-2">
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="hidden" name="must_change_password" value="0">
                        <input type="checkbox"
                               name="must_change_password"
                               value="1"
                               checked
                               class="w-4 h-4 mt-0.5 rounded border-dark-600 bg-dark-700 text-brand-500 focus:ring-brand-500 focus:ring-offset-dark-900">
                        <div>
                            <span class="text-sm font-medium text-white group-hover:text-brand-400 transition-colors">
                                {{ app()->getLocale() === 'ar' ? 'إلزام الموظف بتغيير كلمة المرور عند أول تسجيل دخول' : 'Require password change on first login' }}
                            </span>
                            <p class="text-xs text-dark-400">
                                {{ app()->getLocale() === 'ar' ? 'سيُطلب من الموظف إدخال كلمة مرور جديدة خاصة به فور تسجيل دخوله.' : 'Employee will be prompted to choose their own personal password upon signing in.' }}
                            </p>
                        </div>
                    </label>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center justify-between pt-5 border-t border-dark-700">
                    <a href="{{ route('admin.employees.show', $employee) }}" class="btn-secondary text-sm">
                        {{ app()->getLocale() === 'ar' ? 'إلغاء' : 'Cancel' }}
                    </a>

                    <button type="submit" class="btn-primary text-sm px-6 py-2.5 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>{{ app()->getLocale() === 'ar' ? 'حفظ وتعيين كلمة المرور' : 'Save & Set Password' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.classList.add('text-brand-400');
            } else {
                input.type = 'password';
                btn.classList.remove('text-brand-400');
            }
        }

        document.getElementById('btn-generate-password')?.addEventListener('click', function() {
            // Generate friendly secure password: Word + Year + SpecialChar + RandomDigits
            const words = ['Shawarma', 'Cedar', 'Pita', 'Tahini', 'Falafel', 'Summit', 'Pacific'];
            const chosenWord = words[Math.floor(Math.random() * words.length)];
            const randomNum = Math.floor(100 + Math.random() * 900);
            const chars = '!@#$%';
            const special = chars[Math.floor(Math.random() * chars.length)];
            const generated = `${chosenWord}@${randomNum}`;

            const pwInput = document.getElementById('password');
            const confirmInput = document.getElementById('password_confirmation');
            const infoBox = document.getElementById('generated-info-box');
            const pwText = document.getElementById('generated-pw-text');

            if (pwInput && confirmInput) {
                pwInput.value = generated;
                confirmInput.value = generated;
                pwInput.type = 'text';
                confirmInput.type = 'text';

                if (infoBox && pwText) {
                    pwText.textContent = generated;
                    infoBox.classList.remove('hidden');
                }
            }
        });

        document.getElementById('btn-copy-pw')?.addEventListener('click', function() {
            const pwText = document.getElementById('generated-pw-text')?.textContent;
            if (pwText) {
                navigator.clipboard.writeText(pwText).then(() => {
                    const original = this.textContent;
                    this.textContent = '{{ app()->getLocale() === "ar" ? "تم النسخ!" : "Copied!" }}';
                    setTimeout(() => { this.textContent = original; }, 2000);
                });
            }
        });
    </script>
</x-app-layout>
