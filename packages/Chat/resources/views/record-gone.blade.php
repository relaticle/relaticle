<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ __('This record no longer exists') }} - {{ config('app.name', 'Relaticle') }}</title>

    {{-- Dark mode FOUC prevention, mirrors layouts.filament-standalone --}}
    <script>
        document.documentElement.classList.toggle(
            'dark',
            localStorage.getItem('theme') === 'dark' || ((!localStorage.getItem('theme') || localStorage.getItem('theme') === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches)
        );
    </script>

    @vite(['resources/css/filament/app/theme.css'])
</head>
<body class="font-sans antialiased">
    <div class="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-950">
        <div class="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="mx-auto mb-5 flex size-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                <x-filament::icon icon="ri-inbox-archive-line" class="size-6 text-gray-500 dark:text-gray-400" />
            </div>

            <h1 class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('This record no longer exists') }}
            </h1>

            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('It was deleted after this conversation referenced it.') }}
            </p>

            <a
                href="{{ route('dashboard') }}"
                class="mt-6 inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
            >
                {{ __('Back to Relaticle') }}
            </a>
        </div>
    </div>
</body>
</html>
