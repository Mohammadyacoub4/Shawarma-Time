<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' || session('direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title . ' — ' : '' }}{{ config('app.name', 'Shawarma Time') }} — {{ app()->getLocale() === 'ar' ? 'بوابة الموظفين' : 'Staff Portal' }}</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-dark-900 text-dark-100 antialiased min-h-screen flex flex-col items-center justify-center p-4">

    {{-- Language Switcher --}}
    <div class="absolute top-4 end-4 sm:top-6 sm:end-6 z-20">
        <a href="{{ route('locale.switch', app()->getLocale() === 'ar' ? 'en' : 'ar') }}"
           class="px-2.5 py-1.5 rounded-lg bg-dark-800 hover:bg-dark-700 border border-dark-700 hover:border-dark-600 text-xs font-semibold text-brand-400 hover:text-brand-300 transition-colors flex items-center gap-1.5 shadow-sm"
           title="{{ app()->getLocale() === 'ar' ? 'Switch to English' : 'التحويل إلى العربية' }}">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
            </svg>
            <span>{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</span>
        </a>
    </div>

    {{-- Background subtle pattern --}}
    <div class="fixed inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-brand-500/5 via-dark-900 to-dark-950 pointer-events-none"></div>

    <div class="relative w-full max-w-md z-10">
        {{-- Logo --}}
        <div class="mb-8 text-center">
            <a href="{{ route('login') }}" class="inline-block">
                <img src="{{ asset('logo.png') }}" alt="{{ config('app.name', 'Shawarma Time') }}" class="h-16 w-auto mx-auto mb-3 object-contain drop-shadow-md">
            </a>
            <p class="text-dark-400 text-sm">{{ app()->getLocale() === 'ar' ? 'نظام إدارة الجداول وحضور الموظفين' : 'Staff Scheduling & Timesheet Portal' }}</p>
        </div>

        {{-- Card --}}
        <div class="card">
            <div class="card-body">
                {{ $slot }}
            </div>
        </div>

        {{-- Footer --}}
        <p class="mt-6 text-center text-xs text-dark-600">
            {{ app()->getLocale() === 'ar' ? 'ريتشموند · فانكوفر، كولومبيا البريطانية' : 'Richmond · Vancouver, BC' }} &nbsp;·&nbsp; &copy; {{ date('Y') }} Shawarma Time
        </p>
    </div>

</body>
</html>
