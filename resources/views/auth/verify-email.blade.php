<x-guest-layout>
    <div class="mb-4 text-sm text-dark-300">
        {{ app()->getLocale() === 'ar'
            ? 'شكراً لتسجيلك! قبل البدء، هل يمكنك التحقق من عنوان بريدك الإلكتروني بالنقر على الرابط الذي أرسلناه إليك للتو؟ إذا لم تتلقَ البريد، سنرسل لك رابطاً آخر بكل سرور.'
            : 'Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.' }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-green-400">
            {{ app()->getLocale() === 'ar'
                ? 'تم إرسال رابط تحقق جديد إلى عنوان البريد الإلكتروني الذي قدمته.'
                : 'A new verification link has been sent to the email address you provided during registration.' }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ app()->getLocale() === 'ar' ? 'إعادة إرسال البريد الإلكتروني' : 'Resend Verification Email' }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-dark-400 hover:text-white rounded-md focus:outline-none transition-colors">
                {{ app()->getLocale() === 'ar' ? 'تسجيل الخروج' : 'Log Out' }}
            </button>
        </form>
    </div>
</x-guest-layout>
