<x-guest-layout>
    <div class="mb-4 text-sm text-dark-300">
        {{ app()->getLocale() === 'ar'
            ? 'نسيت كلمة المرور؟ لا توجد مشكلة. أدخل عنوان بريدك الإلكتروني وسنرسل لك رابطاً لإعادة تعيين كلمة المرور لاختيار كلمة مرور جديدة.'
            : 'Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.' }}
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="app()->getLocale() === 'ar' ? 'البريد الإلكتروني' : 'Email address'" />
            <x-text-input id="email" class="block mt-1 w-full text-start" type="email" name="email" :value="old('email')"
                          required autofocus dir="ltr" :placeholder="app()->getLocale() === 'ar' ? 'name@example.com' : 'you@example.com'" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <a href="{{ route('login') }}" class="text-sm text-brand-400 hover:text-brand-300 transition-colors">
                {{ app()->getLocale() === 'ar' ? 'العودة لتسجيل الدخول' : 'Back to login' }}
            </a>

            <x-primary-button>
                {{ app()->getLocale() === 'ar' ? 'إرسال رابط إعادة التعيين' : 'Email Password Reset Link' }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
