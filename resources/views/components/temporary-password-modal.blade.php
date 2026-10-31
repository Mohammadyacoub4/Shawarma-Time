@if (session()->has('temp_password_pending'))
    @php
        $pending = session('temp_password_pending');
        $token = $pending['token'] ?? '';
        $empId = $pending['employee_id'] ?? null;
        $empName = $pending['employee_name'] ?? 'Employee';
    @endphp
    @if ($token && $empId)
        <div x-data="temporaryPasswordViewer('{{ route('admin.employees.retrieve-temporary-password', $empId) }}', '{{ $token }}')" class="p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 space-y-3">
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    <span class="font-semibold text-white text-sm">
                        {{ app()->getLocale() === 'ar' ? "كلمة المرور المؤقتة ({$empName})" : "One-Time Temporary Password ({$empName})" }}
                    </span>
                </div>
                <span class="text-[11px] px-2 py-0.5 rounded bg-amber-500/20 text-amber-200 border border-amber-500/30 font-medium">
                    {{ app()->getLocale() === 'ar' ? 'عرض لمرة واحدة فقط' : 'View Once Only' }}
                </span>
            </div>

            <p class="text-xs text-amber-200/90 leading-relaxed">
                {{ app()->getLocale() === 'ar'
                    ? 'لأسباب أمنية، لن يتم إظهار كلمة المرور مرة أخرى بعد مغادرة الصفحة أو تحديثها. انقر أدناه لإظهارها ونسخها الآن.'
                    : 'For security, this password will never be shown again once you refresh or leave this page. Click below to view and copy it now.' }}
            </p>

            <template x-if="!revealed && !error">
                <div>
                    <button type="button"
                            @click="reveal()"
                            :disabled="loading"
                            class="btn-primary text-xs !bg-amber-600 hover:!bg-amber-500 flex items-center gap-1.5 disabled:opacity-50">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        <span x-text="loading ? '{{ app()->getLocale() === 'ar' ? 'جارٍ التحميل...' : 'Loading...' }}' : '{{ app()->getLocale() === 'ar' ? 'إظهار كلمة المرور المؤقتة' : 'Reveal Temporary Password' }}'"></span>
                    </button>
                </div>
            </template>

            <template x-if="revealed && password">
                <div class="p-3 rounded-lg bg-dark-900/80 border border-amber-500/40 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-dark-400 font-medium">{{ app()->getLocale() === 'ar' ? 'كلمة المرور:' : 'Password:' }}</span>
                        <code class="font-mono text-base font-bold text-amber-300 tracking-wider select-all px-2 py-0.5 rounded bg-dark-950 border border-dark-700" x-text="password"></code>
                    </div>
                    <button type="button"
                            @click="copy()"
                            class="text-xs bg-amber-500/20 hover:bg-amber-500/30 text-amber-200 border border-amber-500/30 px-3 py-1.5 rounded font-semibold transition-colors flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <span x-text="copied ? '{{ app()->getLocale() === 'ar' ? 'تم النسخ!' : 'Copied!' }}' : '{{ app()->getLocale() === 'ar' ? 'نسخ' : 'Copy' }}'"></span>
                    </button>
                </div>
            </template>

            <template x-if="error">
                <div class="p-2.5 rounded bg-red-500/10 border border-red-500/20 text-xs text-red-400" x-text="error"></div>
            </template>
        </div>

        <script>
            function temporaryPasswordViewer(url, token) {
                return {
                    loading: false,
                    revealed: false,
                    password: '',
                    error: '',
                    copied: false,
                    reveal() {
                        this.loading = true;
                        this.error = '';
                        fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                            },
                            body: JSON.stringify({ token: token })
                        })
                        .then(res => res.json().then(data => ({ ok: res.ok, status: res.status, data: data })))
                        .then(result => {
                            this.loading = false;
                            if (result.ok && result.data.password) {
                                this.revealed = true;
                                this.password = result.data.password;
                            } else {
                                this.error = result.data.error || '{{ app()->getLocale() === "ar" ? "تعذر استرجاع كلمة المرور." : "Unable to retrieve password." }}';
                            }
                        })
                        .catch(() => {
                            this.loading = false;
                            this.error = '{{ app()->getLocale() === "ar" ? "حدث خطأ في الاتصال." : "Network error occurred." }}';
                        });
                    },
                    copy() {
                        if (!this.password) return;
                        if (!navigator.clipboard || !navigator.clipboard.writeText) {
                            this.error = '{{ app()->getLocale() === "ar" ? "الحافظة غير مدعومة في هذا المتصفح أو تتطلب اتصالاً آمناً (HTTPS). يرجى نسخ كلمة المرور يدوياً." : "Clipboard is not supported in this browser or requires a secure origin (HTTPS). Please copy the password manually." }}';
                            return;
                        }
                        navigator.clipboard.writeText(this.password)
                            .then(() => {
                                this.copied = true;
                                setTimeout(() => { this.copied = false; }, 2500);
                            })
                            .catch(() => {
                                this.error = '{{ app()->getLocale() === "ar" ? "تعذر النسخ إلى الحافظة تلقائياً. يرجى تحديد كلمة المرور ونسخها يدوياً." : "Failed to copy to clipboard automatically. Please select and copy the password manually." }}';
                            });
                    }
                };
            }
        </script>
    @endif
@endif

