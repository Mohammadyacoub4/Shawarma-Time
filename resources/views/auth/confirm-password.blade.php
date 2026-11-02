<x-guest-layout>
    <div class="mb-4 text-sm text-dark-300">
        {{ app()->getLocale() === 'ar'
            ? 'هذه منطقة آمنة من النظام. يُرجى تأكيد كلمة المرور للمتابعة.'
            : 'This is a secure area of the application. Please confirm your password before continuing.' }}
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
        @csrf

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="app()->getLocale() === 'ar' ? 'كلمة المرور' : 'Password'" />

            <x-text-input id="password" class="block mt-1 w-full text-start"
                            type="password"
                            name="password"
                            required autocomplete="current-password" dir="ltr" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex justify-end mt-4">
            <x-primary-button class="w-full justify-center">
                {{ app()->getLocale() === 'ar' ? 'تأكيد' : 'Confirm' }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
