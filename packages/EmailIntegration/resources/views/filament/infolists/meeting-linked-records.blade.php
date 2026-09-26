@php
    /** @var list<array{name: string, type: string}> $items */
    $items = $getState();
@endphp

<div class="space-y-2">
    @foreach ($items as $item)
        <div class="flex items-center justify-between gap-3">
            <span class="truncate font-medium text-gray-950 dark:text-white">{{ $item['name'] }}</span>
            <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ $item['type'] }}</span>
        </div>
    @endforeach
</div>
