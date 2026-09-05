@props(['title'])

<x-layouts::filament-standalone :title="$title">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <div class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col items-center gap-4 px-6 pt-8 pb-6 text-center">
                    {{ $icon }}

                    <div class="space-y-1.5">
                        <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                            {{ $heading }}
                        </h1>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $description }}
                        </p>
                    </div>
                </div>

                @isset($details)
                    <div class="space-y-5 border-t border-gray-200 px-6 py-6 dark:border-gray-800">
                        {{ $details }}
                    </div>
                @endisset

                <div class="border-t border-gray-200 px-6 py-6 dark:border-gray-800">
                    {{ $actions }}
                </div>
            </div>
        </div>
    </div>
</x-layouts::filament-standalone>
