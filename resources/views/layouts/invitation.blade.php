@props(['title'])

<x-layouts::filament-standalone :title="$title">
    {{-- Back can restore this page from the browser cache with Join still spinning, so ask the server again. --}}
    <div class="flex min-h-screen flex-col" x-data x-on:pageshow.window="if ($event.persisted) window.location.reload()">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}" aria-label="{{ config('app.name') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <div class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <x-workspaces.invitation-panel>
                    <x-slot:icon>{{ $icon }}</x-slot:icon>
                    <x-slot:heading>{{ $heading }}</x-slot:heading>
                    <x-slot:description>{{ $description }}</x-slot:description>

                    @isset($details)
                        <x-slot:details>{{ $details }}</x-slot:details>
                    @endisset

                    <x-slot:actions>{{ $actions }}</x-slot:actions>
                </x-workspaces.invitation-panel>
            </div>
        </div>
    </div>
</x-layouts::filament-standalone>
