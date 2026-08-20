@props([
    'title',
    'description' => null,
    'ogTitle' => null,
    'ogDescription' => null,
    'ogImage' => null,
    'ogType' => 'website',
    'canonical' => null,
    'robots' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-layout.head
        :title="$title"
        :description="$description"
        :og-title="$ogTitle"
        :og-description="$ogDescription"
        :og-image="$ogImage"
        :og-type="$ogType"
        :canonical="$canonical"
        :robots="$robots" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('header')

    @if(app()->isProduction() && !empty(config('services.fathom.site_id')))
        <!-- Fathom - beautiful, simple website analytics -->
        <script src="https://cdn.usefathom.com/script.js" data-site="{{ config('services.fathom.site_id') }}" defer></script>
        <!-- / Fathom -->
    @endif
</head>
<body class="font-sans antialiased text-gray-800">

<nav aria-label="{{ __('Skip links') }}">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:px-4 focus:py-2 focus:bg-white focus:text-gray-900 focus:rounded-md focus:shadow-lg focus:ring-2 focus:ring-primary dark:focus:bg-gray-900 dark:focus:text-white">
        Skip to main content
    </a>
</nav>

<x-layout.header/>

<main id="main-content" tabindex="-1">
    {{ $slot }}
</main>

<x-layout.footer/>

</body>
</html>
