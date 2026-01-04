@props([
    'formats',
    'selected' => null,
    'label' => 'Format',
    'field' => null,
    'needsConfirmation' => false,
])

<div
    x-data="{ open: false }"
    x-on:click.outside="open = false"
    class="relative flex items-center gap-2"
>
    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}:</span>
    <button
        type="button"
        x-on:click="open = !open"
        @class([
            'flex items-center gap-1.5 text-xs rounded-md border py-1 px-2 focus:outline-none focus:ring-1 focus:ring-primary-500',
            'border-warning-300 dark:border-warning-700 bg-warning-50 dark:bg-warning-950' => $needsConfirmation,
            'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700' => !$needsConfirmation,
        ])
    >
        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $selected?->getLabel() ?? 'Select format' }}</span>
        <x-filament::icon icon="heroicon-m-chevron-down" class="h-3.5 w-3.5 text-gray-400" x-bind:class="{ 'rotate-180': open }" />
    </button>

    @if ($needsConfirmation)
        <span class="text-xs text-warning-600 dark:text-warning-400" title="Low confidence detection - please verify">
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4" />
        </span>
    @endif

    {{-- Dropdown Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute top-full right-0 mt-1 w-72 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50 overflow-hidden"
    >
        @foreach ($formats as $format)
            @php
                $isSelected = $selected?->value === $format->value;
            @endphp
            <button
                type="button"
                @if ($field)
                    wire:click="changeDateFormat('{{ $field }}', '{{ $format->value }}')"
                @endif
                x-on:click="open = false"
                @class([
                    'w-full text-left px-3 py-2 border-b border-gray-100 dark:border-gray-700 last:border-b-0 transition-colors',
                    'bg-primary-50 dark:bg-primary-950' => $isSelected,
                    'hover:bg-gray-50 dark:hover:bg-gray-700' => !$isSelected,
                ])
            >
                <div class="flex items-center justify-between">
                    <span @class([
                        'text-sm font-medium',
                        'text-primary-700 dark:text-primary-300' => $isSelected,
                        'text-gray-900 dark:text-gray-100' => !$isSelected,
                    ])>{{ $format->getLabel() }}</span>
                    @if ($isSelected)
                        <x-filament::icon icon="heroicon-m-check" class="h-4 w-4 text-primary-600 dark:text-primary-400" />
                    @endif
                </div>
                <div class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                    {{ implode(' • ', $format->getExamples()) }}
                </div>
            </button>
        @endforeach
    </div>
</div>
