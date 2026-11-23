{{-- Static twin of a record page in the app panel: the title row, the view row and the Sort and filter row.
     Mirrors resources/views/filament/app/view-switcher.blade.php and tables/filter-bar.blade.php. --}}
@props([
    'title',
    'icon',
    'view',
    'layout' => 'list',
    'newLabel',
    /** @var list<array{field: string, icon: string, condition: string, value: string}> */
    'filters' => [],
])

@php
    $button = 'inline-flex h-8 shrink-0 items-center gap-1 rounded-lg bg-white px-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10';
    $ghost = 'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-lg border border-dashed border-gray-300 px-2 text-sm font-medium text-gray-500 dark:border-white/15 dark:text-gray-400';
    $tile = $layout === 'board' ? ['bg-orange-500', 'lucide-square-kanban'] : ['bg-green-500', 'lucide-table-2'];
@endphp

<x-cloud::marketing.window :attributes="$attributes">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-2 dark:border-white/[0.06]">
        <span class="flex shrink-0 items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
            <x-dynamic-component :component="$icon" class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true"/>
            {{ $title }}
        </span>
        <span class="{{ $button }} min-w-0 gap-1.5">
            <x-lucide-messages-square class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true"/>
            <span class="truncate">{{ __('Ask :name', ['name' => config('chat.assistant_name')]) }}</span>
        </span>
    </div>

    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/[0.06]">
        <span class="flex min-w-0 items-center gap-2">
            <span class="{{ $button }} min-w-0">
                <span class="flex size-5 shrink-0 items-center justify-center rounded-md text-white {{ $tile[0] }}">
                    <x-dynamic-component :component="$tile[1]" class="size-3.5" aria-hidden="true"/>
                </span>
                <span class="truncate">{{ $view }}</span>
                <x-lucide-chevron-down class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true"/>
            </span>
            <span class="{{ $button }} max-sm:hidden">
                <x-lucide-sliders-horizontal class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true"/>
                {{ __('filament/panel.tables.views.settings') }}
                <x-lucide-chevron-down class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true"/>
            </span>
        </span>
        <span class="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg bg-primary-600 px-2.5 text-sm font-medium text-white">
            <x-lucide-plus class="h-4 w-4" aria-hidden="true"/>
            <span class="max-sm:hidden">{{ $newLabel }}</span>
        </span>
    </div>

    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/[0.06]">
        <span @class([$ghost, 'max-sm:hidden' => $filters !== []])>
            <x-lucide-arrow-down-wide-narrow class="h-4 w-4 shrink-0" aria-hidden="true"/>
            {{ __('filament/panel.tables.filters.sort') }}
        </span>
        @forelse($filters as $filter)
            <span class="inline-flex h-7 min-w-0 items-center divide-x divide-gray-200 rounded-lg bg-white text-sm ring-1 ring-gray-200 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                <span class="inline-flex h-full min-w-0 items-center gap-1.5 px-2 font-medium text-gray-700 dark:text-gray-200">
                    <x-dynamic-component :component="$filter['icon']" class="h-3.5 w-3.5 shrink-0 text-gray-400" aria-hidden="true"/>
                    <span class="truncate">{{ $filter['field'] }}</span>
                </span>
                <span class="inline-flex h-full shrink-0 items-center px-2 text-gray-500 dark:text-gray-400">{{ $filter['condition'] }}</span>
                <span class="inline-flex h-full shrink-0 items-center px-2 font-medium text-gray-950 dark:text-white">{{ $filter['value'] }}</span>
                <span class="inline-flex h-full shrink-0 items-center px-1 text-gray-500 max-sm:hidden dark:text-gray-400">
                    <x-lucide-ellipsis-vertical class="h-3.5 w-3.5" aria-hidden="true"/>
                </span>
            </span>
        @empty
            <span class="{{ $ghost }}">
                <x-lucide-plus class="h-4 w-4 shrink-0" aria-hidden="true"/>
                {{ __('filament/panel.tables.filters.add') }}
            </span>
        @endforelse
        @if($filters !== [])
            <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-lg border border-dashed border-gray-300 text-gray-500 max-sm:hidden dark:border-white/15 dark:text-gray-400">
                <x-lucide-plus class="h-3.5 w-3.5" aria-hidden="true"/>
            </span>
        @endif
    </div>

    {{ $slot }}
</x-cloud::marketing.window>
