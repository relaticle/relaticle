<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Relaticle') }}</title>

    <!-- Dark mode FOUC prevention -->
    <script>
        document.documentElement.classList.toggle(
            'dark',
            localStorage.getItem('theme') === 'dark' || ((!localStorage.getItem('theme') || localStorage.getItem('theme') === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches)
        );
    </script>

    @filamentStyles
    @vite(['resources/css/filament/app/theme.css'])
</head>
<body class="font-sans antialiased">
    {{ $slot }}

    @livewireScripts
    @filamentScripts
</body>
</html>
