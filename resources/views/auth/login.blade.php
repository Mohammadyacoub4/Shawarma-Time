<x-guest-layout>
    <x-auth-session-status class="mb-5" :status="session('status')" />

    <h1 class="text-xl font-bold text-white mb-1">Welcome back</h1>
    <p class="text-sm text-dark-400 mb-6">Sign in to your Shawarma Time staff account</p>

    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Email address" />
            <x-text-input id="email" type="email" name="email" :value="old('email')"
                          required autofocus autocomplete="username" placeholder="you@example.com" />
            <x-input-error :messages="$errors->get('email')" />
        </div>

        <div>
            <x-input-label for="password" value="Password" />
            <x-text-input id="password" type="password" name="password"
                          required autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->get('password')" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember_me" class="flex items-center gap-2 cursor-pointer">
                <input id="remember_me" type="checkbox" name="remember"
                       class="w-4 h-4 rounded border-dark-600 bg-dark-700 text-brand-500 focus:ring-brand-500 focus:ring-offset-dark-800">
                <span class="text-sm text-dark-300">Remember me</span>
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}"
                   class="text-sm text-brand-400 hover:text-brand-300 transition-colors">
                    Forgot password?
                </a>
            @endif
        </div>

        <x-primary-button class="w-full justify-center">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
            </svg>
            Sign In
        </x-primary-button>
    </form>
</x-guest-layout>
