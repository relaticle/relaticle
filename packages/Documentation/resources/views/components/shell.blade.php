@props([
    'title',
    'description',
    'ogTitle' => null,
    'ogDescription' => null,
])

<x-guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$ogTitle ?? $title"
    :ogDescription="$ogDescription ?? $description">
    @pushonce('header')
        @vite(['packages/Documentation/resources/js/documentation.js', 'packages/Documentation/resources/css/documentation.css'])
    @endpushonce

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            {{ $slot }}
        </div>
    </div>
</x-guest-layout>
