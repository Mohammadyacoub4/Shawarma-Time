<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title . ' — ' : '' }}{{ config('app.name', 'Shawarma Time') }} Staff Portal</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-dark-900 text-dark-100 antialiased min-h-screen flex flex-col items-center justify-center p-4">

    {{-- Background subtle pattern --}}
    <div class="fixed inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-brand-500/5 via-dark-900 to-dark-950 pointer-events-none"></div>

    <div class="relative w-full max-w-md z-10">
        {{-- Logo --}}
        <div class="mb-8 text-center">
            <a href="{{ route('login') }}" class="inline-block">
                <img src="{{ asset('logo.png') }}" alt="Shawarma Time" class="h-14 w-auto mx-auto mb-3">
            </a>
            <p class="text-dark-400 text-sm">Staff Scheduling & Timesheet Portal</p>
        </div>

        {{-- Card --}}
        <div class="card">
            <div class="card-body">
                {{ $slot }}
            </div>
        </div>

        {{-- Footer --}}
        <p class="mt-6 text-center text-xs text-dark-600">
            Richmond · Vancouver, BC &nbsp;·&nbsp; &copy; {{ date('Y') }} Shawarma Time
        </p>
    </div>

</body>
</html>
