@props([
    'src' => null,
    'icon' => null,
])

@if ($src)
    <img src="{{ $src }}" alt="" class="size-14 rounded-xl ring-1 ring-gray-200 dark:ring-gray-700">
@else
    <span class="flex size-14 items-center justify-center rounded-xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
        <x-filament::icon :icon="$icon" class="size-7" />
    </span>
@endif
