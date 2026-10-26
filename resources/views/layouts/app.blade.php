<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' || session('direction') === 'rtl' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title . ' — ' : '' }}{{ config('app.name', 'Shawarma Time') }} — إدارة الحضور والدوام</title>
    <meta name="description" content="نظام إدارة حضور ودوام موظفي شاورما تايم — Shawarma Time Staff Attendance & Scheduling">

    <!-- Preconnect for fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Alpine.js -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-dark-900 text-dark-100 antialiased min-h-screen">

    {{-- TOP NAVIGATION --}}
    <nav x-data="{ open: false, userMenu: false }" class="bg-dark-950 border-b border-dark-800 sticky top-0 z-50 shadow-lg shadow-black/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">

                {{-- LOGO --}}
                <div class="flex items-center gap-8">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 shrink-0 group">
                        <img src="{{ asset('logo.png') }}" alt="Shawarma Time" class="h-9 w-auto object-contain transition-opacity duration-200 group-hover:opacity-90">
                    </a>

                    {{-- DESKTOP NAV LINKS --}}
                    <div class="hidden md:flex items-center gap-1">
                        <a href="{{ route('dashboard') }}"
                           class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                  {{ request()->routeIs('dashboard', 'admin.dashboard', 'employee.dashboard')
                                     ? 'bg-brand-500/10 text-brand-400 font-semibold'
                                     : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                            <span class="flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                                </svg>
                                {{ app()->getLocale() === 'ar' ? 'لوحة التحكم' : 'Dashboard' }}
                            </span>
                        </a>

                        @if(Auth::user()->isAdmin())
                            <a href="{{ route('admin.employees.index') }}"
                               class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                      {{ request()->routeIs('admin.employees.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'الموظفون' : 'Employees' }}
                                </span>
                            </a>
                            <a href="{{ route('admin.schedules.index') }}"
                               class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                      {{ request()->routeIs('admin.schedules.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'الجداول والمناوبات' : 'Schedules' }}
                                </span>
                            </a>
                            <a href="{{ route('admin.timesheets.index') }}"
                               class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                      {{ request()->routeIs('admin.timesheets.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'سجلات الدوام' : 'Timesheets' }}
                                </span>
                            </a>
                            <a href="{{ route('admin.reports.index') }}"
                               class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                      {{ request()->routeIs('admin.reports.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'التقارير' : 'Reports' }}
                                </span>
                            </a>
                        @else
                            <a href="{{ route('employee.schedules.index') }}"
                               class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                      {{ request()->routeIs('employee.schedules.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'جدول مناوباتي' : 'My Schedule' }}
                                </span>
                            </a>
                            <a href="{{ route('employee.timesheets.create') }}"
                               class="px-3 py-2 rounded-md text-sm font-medium transition-all duration-150
                                      {{ request()->routeIs('employee.timesheets.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }}">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ app()->getLocale() === 'ar' ? 'سجل يدوي' : 'Manual Entry' }}
                                </span>
                            </a>
                        @endif
                    </div>
                </div>

                {{-- RIGHT: LANGUAGE SWITCHER, USER MENU & MOBILE TOGGLE --}}
                <div class="flex items-center gap-2 sm:gap-3">
                    {{-- Language Switcher --}}
                    <a href="{{ route('locale.switch', app()->getLocale() === 'ar' ? 'en' : 'ar') }}"
                       class="px-2.5 py-1.5 rounded-lg bg-dark-800 hover:bg-dark-700 border border-dark-700 hover:border-dark-600 text-xs font-semibold text-brand-400 hover:text-brand-300 transition-colors flex items-center gap-1.5"
                       title="{{ app()->getLocale() === 'ar' ? 'Switch to English' : 'التحويل إلى العربية' }}">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                        </svg>
                        <span>{{ app()->getLocale() === 'ar' ? 'English' : 'عربي' }}</span>
                    </a>

                    {{-- Role badge --}}
                    @if(Auth::user()->isAdmin())
                        <span class="hidden sm:inline-flex badge bg-brand-500/20 text-brand-400 ring-1 ring-brand-500/30 text-xs">
                            {{ app()->getLocale() === 'ar' ? 'مدير النظام' : 'Admin' }}
                        </span>
                    @else
                        <span class="hidden sm:inline-flex badge bg-blue-500/20 text-blue-400 ring-1 ring-blue-500/30 text-xs">
                            {{ app()->getLocale() === 'ar' ? 'موظف' : 'Staff' }}
                        </span>
                    @endif

                    {{-- User dropdown --}}
                    <div class="relative" x-data="{ open: false }" @click.away="open = false">
                        <button @click="open = !open"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg bg-dark-800 hover:bg-dark-700 border border-dark-700 hover:border-dark-600 text-sm font-medium text-dark-200 hover:text-white transition-all duration-150">
                            <span class="w-7 h-7 rounded-full bg-brand-500/20 border border-brand-500/30 flex items-center justify-center text-brand-400 font-bold text-xs">
                                {{ mb_substr(Auth::user()->name, 0, 1) }}
                            </span>
                            <span class="hidden sm:block max-w-[120px] truncate">{{ Auth::user()->name }}</span>
                            <svg class="w-4 h-4 text-dark-400 transition-transform duration-150" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                            </svg>
                        </button>

                        <div x-show="open"
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             class="absolute {{ app()->getLocale() === 'ar' ? 'left-0' : 'right-0' }} mt-2 w-48 bg-dark-800 border border-dark-700 rounded-xl shadow-xl shadow-black/40 z-50 py-1">
                            <div class="px-4 py-2 border-b border-dark-700">
                                <p class="text-xs text-dark-400">{{ app()->getLocale() === 'ar' ? 'تم تسجيل الدخول كـ' : 'Signed in as' }}</p>
                                <p class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</p>
                            </div>
                            <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-200 hover:bg-dark-700 hover:text-white transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                {{ app()->getLocale() === 'ar' ? 'الملف الشخصي' : 'Profile' }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="w-full flex items-center gap-2 px-4 py-2 text-sm text-dark-200 hover:bg-dark-700 hover:text-red-400 transition-colors {{ app()->getLocale() === 'ar' ? 'text-right' : 'text-left' }}">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>
                                    {{ app()->getLocale() === 'ar' ? 'تسجيل الخروج' : 'Sign Out' }}
                                </button>
                            </form>
                        </div>
                    </div>

                    {{-- Mobile menu button --}}
                    <button @click="open = !open" class="md:hidden p-2 rounded-lg text-dark-400 hover:text-white hover:bg-dark-800 transition-colors">
                        <svg x-show="!open" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                        <svg x-show="open" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            </div>
        </div>

        {{-- MOBILE MENU --}}
        <div x-show="open"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="md:hidden border-t border-dark-800 bg-dark-950 px-4 py-3 space-y-1">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('dashboard', 'admin.dashboard', 'employee.dashboard') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                {{ app()->getLocale() === 'ar' ? 'لوحة التحكم' : 'Dashboard' }}
            </a>
            @if(Auth::user()->isAdmin())
                <a href="{{ route('admin.employees.index') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('admin.employees.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                    {{ app()->getLocale() === 'ar' ? 'الموظفون' : 'Employees' }}
                </a>
                <a href="{{ route('admin.schedules.index') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('admin.schedules.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                    {{ app()->getLocale() === 'ar' ? 'الجداول والمناوبات' : 'Schedules' }}
                </a>
                <a href="{{ route('admin.timesheets.index') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('admin.timesheets.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                    {{ app()->getLocale() === 'ar' ? 'سجلات الدوام' : 'Timesheets' }}
                </a>
                <a href="{{ route('admin.reports.index') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('admin.reports.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                    {{ app()->getLocale() === 'ar' ? 'التقارير' : 'Reports' }}
                </a>
            @else
                <a href="{{ route('employee.schedules.index') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('employee.schedules.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                    {{ app()->getLocale() === 'ar' ? 'جدول مناوباتي' : 'My Schedule' }}
                </a>
                <a href="{{ route('employee.timesheets.create') }}" class="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm {{ request()->routeIs('employee.timesheets.*') ? 'bg-brand-500/10 text-brand-400 font-semibold' : 'text-dark-300 hover:bg-dark-800 hover:text-white' }} transition-colors">
                    {{ app()->getLocale() === 'ar' ? 'سجل يدوي' : 'Manual Entry' }}
                </a>
            @endif
        </div>
    </nav>

    {{-- PAGE HEADER --}}
    @isset($header)
        <div class="bg-dark-900 border-b border-dark-800">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
                {{ $header }}
            </div>
        </div>
    @endisset

    {{-- MAIN CONTENT --}}
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {{ $slot }}
    </main>

    {{-- FOOTER --}}
    <footer class="mt-16 border-t border-dark-800 bg-dark-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <img src="{{ asset('logo.png') }}" alt="Shawarma Time" class="h-7 w-auto opacity-70">
                <span class="text-dark-400 text-sm font-medium">شاورما تايم — Shawarma Time</span>
            </div>
            <div class="text-dark-500 text-xs">
                نظام إدارة حضور ودوام الموظفين &nbsp;·&nbsp; {{ config('app.timezone', 'Asia/Amman') }} &nbsp;·&nbsp; &copy; {{ date('Y') }} Shawarma Time
            </div>
        </div>
    </footer>

</body>
</html>
