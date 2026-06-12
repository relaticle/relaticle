@props([
    'type' => 'company', 
    'id' => null,
    'name' => '',
    'fields' => [],
    'url' => null,
])

@php
    $icons = [
        'company' => 'heroicon-o-building-office',
        'person' => 'heroicon-o-user',
        'opportunity' => 'heroicon-o-currency-dollar',
        'task' => 'heroicon-o-clipboard-document-check',
        'note' => 'heroicon-o-document-text',
    ];
    $colors = [
        'company' => 'text-blue-600 bg-blue-50 dark:text-blue-400 dark:bg-blue-900/30',
        'person' => 'text-emerald-600 bg-emerald-50 dark:text-emerald-400 dark:bg-emerald-900/30',
        'opportunity' => 'text-amber-600 bg-amber-50 dark:text-amber-400 dark:bg-amber-900/30',
        'task' => 'text-purple-600 bg-purple-50 dark:text-purple-400 dark:bg-purple-900/30',
        'note' => 'text-gray-600 bg-gray-50 dark:text-gray-400 dark:bg-gray-900/30',
    ];
    $icon = $icons[$type] ?? 'heroicon-o-document';
    $color = $colors[$type] ?? $colors['note'];
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800 my-2']) }}>
    <div class="flex items-start gap-3">
        <div class="shrink-0 rounded-lg p-2 {{ $color }}">
            <x-dynamic-component :component="$icon" class="h-5 w-5" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="mb-1 flex items-center gap-2">
                @if($url)
                    <a
                        href="{{ $url }}"
                        class="truncate text-sm font-semibold text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400"
                        wire:navigate
                    >
                        {{ $name }}
                    </a>
                @else
                    <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $name }}
                    </span>
                @endif
                <span class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium capitalize text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                    {{ $type }}
                </span>
            </div>
            @if(!empty($fields))
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
                    @foreach(array_slice($fields, 0, 4) as $field)
                        <div class="flex items-baseline gap-1">
                            <dt class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                {{ $field['label'] }}:
                            </dt>
                            <dd class="truncate text-xs text-gray-700 dark:text-gray-300">
                                {{ $field['value'] }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </div>
    </div>
</div>
