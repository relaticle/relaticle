@props([
    'label',
    'value',
])

<div class="flex flex-col gap-0.5 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3 dark:border-gray-800 dark:bg-gray-800/40">
    <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ $label }}</span>
    <span class="text-sm font-medium break-all text-gray-900 dark:text-white">{{ $value }}</span>
</div>
