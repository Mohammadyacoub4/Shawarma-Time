<x-guest-layout>
    <div class="mb-4 text-sm text-dark-300">
        {{ app()->getLocale() === 'ar'
            ? 'لدواعي الأمان، يجب عليك تعيين كلمة مرور جديدة قبل المتابعة.'
            : 'For security, you must set a new password before continuing.' }}
    </div>

    <form method="POST" action="{{ route('password.force.update') }}" class="space-y-4">
        @csrf
        @method('PUT')

        <div>
            <x-input-label for="password" :value="app()->getLocale() === 'ar' ? 'كلمة المرور الجديدة' : 'New password'" />
            <x-text-input id="password" class="block mt-1 w-full text-start" type="password" name="password" required autocomplete="new-password" dir="ltr" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="app()->getLocale() === 'ar' ? 'تأكيد كلمة المرور' : 'Confirm password'" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full text-start" type="password" name="password_confirmation" required autocomplete="new-password" dir="ltr" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button class="w-full justify-center">
                {{ app()->getLocale() === 'ar' ? 'تحديث كلمة المرور' : 'Update password' }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
