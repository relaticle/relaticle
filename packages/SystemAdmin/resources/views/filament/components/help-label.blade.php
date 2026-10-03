<span class="inline-flex items-center gap-1">
    <span>{{ $label }}</span>
    <span
        x-tooltip="{ content: @js($help), theme: $store.theme, maxWidth: 320 }"
        tabindex="0"
        role="img"
        aria-label="{{ $help }}"
        class="inline-flex cursor-help text-gray-400 hover:text-gray-600 focus:text-gray-600 focus:outline-none dark:text-gray-500 dark:hover:text-gray-300 dark:focus:text-gray-300"
    >
        <x-filament::icon icon="heroicon-m-information-circle" class="size-4" />
    </span>
</span>
