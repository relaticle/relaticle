@props(['icon'])

<li class="flex items-start gap-3">
    <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
        <x-filament::icon :icon="$icon" class="size-3.5" />
    </span>

    <span class="min-w-0 text-sm text-gray-600 dark:text-gray-300">{{ $slot }}</span>
</li>
