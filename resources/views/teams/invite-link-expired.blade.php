<x-layouts::filament-standalone :title="__('teams.invite_link.expired.heading')">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <div class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col items-center gap-4 px-6 pt-8 pb-6 text-center">
                    <span class="flex size-14 items-center justify-center rounded-xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                        <svg class="size-7" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
                        </svg>
                    </span>

                    <div class="space-y-1.5">
                        <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                            {{ __('teams.invite_link.expired.heading') }}
                        </h1>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('teams.invite_link.expired.body') }}
                        </p>
                    </div>
                </div>

                <div class="border-t border-gray-200 px-6 py-6 dark:border-gray-800">
                    <a href="{{ url()->getAppUrl() }}" class="inline-flex h-10 w-full items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-white transition-colors hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        {{ __('teams.invite_link.expired.action') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts::filament-standalone>
