@props([
    'heading',
    'description',
    'action',
    'secondaryAction' => null,
    'icon' => 'heroicon-o-envelope',
    'compact' => false,
])

@if ($compact)
    <div data-connect-card class="flex flex-col gap-3 border-b border-gray-200/60 px-4 py-4 sm:flex-row sm:items-center lg:px-6 dark:border-white/10">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
            <x-filament::icon :icon="$icon" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
        </div>

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $heading }}</p>

            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        </div>

        <div class="flex flex-wrap gap-3">
            {{ $action }}

            @if ($secondaryAction?->isVisible())
                {{ $secondaryAction }}
            @endif
        </div>
    </div>
@else
    <div class="flex flex-col items-center justify-center gap-3 px-8 py-16 text-center">
        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
            <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-400 dark:text-gray-500" />
        </div>

        <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $heading }}</p>

        <p class="max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>

        <div class="mt-2 flex flex-wrap justify-center gap-3">
            {{ $action }}

            @if ($secondaryAction?->isVisible())
                {{ $secondaryAction }}
            @endif
        </div>
    </div>
@endif
