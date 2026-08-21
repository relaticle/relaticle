<x-layouts::filament-standalone :title="__('teams.invite_link.expired.heading')">
    <div class="flex min-h-screen items-center justify-center">
        <div class="mx-auto max-w-md px-6 py-12 text-center">
            <h1 class="text-2xl font-bold text-gray-950 dark:text-white">
                {{ __('teams.invite_link.expired.heading') }}
            </h1>

            <p class="mt-4 text-gray-500 dark:text-gray-400">
                {{ __('teams.invite_link.expired.body') }}
            </p>

            <div class="mt-8">
                <x-filament::button tag="a" :href="url()->getAppUrl()">
                    {{ __('teams.invite_link.expired.action') }}
                </x-filament::button>
            </div>
        </div>
    </div>
</x-layouts::filament-standalone>
