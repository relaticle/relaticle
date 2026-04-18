<div class="flex items-start gap-3 py-2" data-type="{{ $entry->type }}" data-event="{{ $entry->event }}">
    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500">
        @if ($entry->icon)
            <x-filament::icon :icon="$entry->icon" class="h-4 w-4" />
        @else
            <x-filament::icon icon="heroicon-o-bell" class="h-4 w-4" />
        @endif
    </div>
    <div class="flex-1">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium text-gray-900 dark:text-gray-100">
                {{ $entry->title ?? \Illuminate\Support\Str::headline($entry->event) }}
            </span>
            <time class="text-xs text-gray-500" datetime="{{ $entry->occurredAt->toIso8601String() }}">
                {{ $entry->occurredAt->diffForHumans() }}
            </time>
        </div>
        @if ($entry->description)
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $entry->description }}</p>
        @endif
    </div>
</div>
