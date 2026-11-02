<x-guest-layout>
    <h1 class="text-xl font-bold text-white mb-1">{{ app()->getLocale() === 'ar' ? 'إعادة تعيين كلمة المرور' : 'Reset Password' }}</h1>
    <p class="text-sm text-dark-400 mb-6">{{ app()->getLocale() === 'ar' ? 'أدخل كلمة المرور الجديدة لحسابك' : 'Enter your new account password' }}</p>

    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="app()->getLocale() === 'ar' ? 'البريد الإلكتروني' : 'Email'" />
            <x-text-input id="email" class="block mt-1 w-full text-start" type="email" name="email" :value="old('email', $request->email)" required autofocus autocomplete="username" dir="ltr" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="app()->getLocale() === 'ar' ? 'كلمة المرور الجديدة' : 'New Password'" />
            <x-text-input id="password" class="block mt-1 w-full text-start" type="password" name="password" required autocomplete="new-password" dir="ltr" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="app()->getLocale() === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm Password'" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full text-start"
                                type="password"
                                name="password_confirmation" required autocomplete="new-password" dir="ltr" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button class="w-full justify-center">
                {{ app()->getLocale() === 'ar' ? 'إعادة تعيين كلمة المرور' : 'Reset Password' }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
