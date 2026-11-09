<div class="flex flex-col items-center px-6 py-12 text-center">
    <span class="flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
        <x-filament::icon :icon="$icon" class="size-5" />
    </span>

    <p class="mt-3 text-sm font-medium text-gray-950 dark:text-white">{{ $heading }}</p>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>

    @if ($settingsUrl)
        <x-filament::button
            tag="a"
            :href="$settingsUrl"
            color="gray"
            size="sm"
            class="mt-4"
            x-on:click="hide(false)"
        >
            {{ __('notifications.panel.empty.settings') }}
        </x-filament::button>
    @endif
</div>
