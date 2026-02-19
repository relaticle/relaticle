@props(['title', 'description' => 'Relaticle - The Next-Generation Open-Source CRM Platform for modern businesses', 'ogTitle' => null, 'ogDescription' => null, 'ogImage' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="description" content="{{ $description }}">

    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="{{ $ogTitle ?? $title ?? config('app.name', 'Relaticle') }}"/>
    <meta property="og:description" content="{{ $ogDescription ?? $description }}"/>
    <meta property="og:image" content="{{ $ogImage ?? url('/images/og-image.jpg') }}"/>
    <meta property="og:url" content="{{ request()->getUri() }}"/>
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="{{ config('app.name', 'Relaticle') }}" />
    <meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}" />

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="{{ $ogTitle ?? $title ?? config('app.name', 'Relaticle') }}" />
    <meta name="twitter:description" content="{{ $ogDescription ?? $description }}" />
    <meta name="twitter:image" content="{{ $ogImage ?? url('/images/og-image.jpg') }}" />

    <title>{{ $title ?? config('app.name', 'Relaticle - The Next-Generation Open-Source CRM Platform') }}</title>
    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Relaticle" />
    <link rel="manifest" href="/site.webmanifest" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('header')

    <!-- Styles -->
    @livewireStyles

    @if(app()->isProduction() && !empty(config('services.fathom.site_id')))
        <!-- Fathom - beautiful, simple website analytics -->
        <script src="https://cdn.usefathom.com/script.js" data-site="{{ config('services.fathom.site_id') }}" defer></script>
        <!-- / Fathom -->
    @endif
</head>
<body class="font-sans antialiased text-gray-800">

<x-layout.header/>

<!-- Main Content -->
{{ $slot }}

<x-layout.footer/>

@livewireScripts
</body>
</html>
