@php
    $record = $getRecord();
@endphp

<div class="flex items-center gap-3 py-1">
    @if ($record['avatar_url'] !== null)
        <img
            src="{{ $record['avatar_url'] }}"
            alt=""
            class="size-8 shrink-0 rounded-full object-cover"
        />
    @else
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5 dark:text-gray-500">
            <x-filament::icon icon="heroicon-m-envelope" class="size-4"/>
        </span>
    @endif

    <div class="min-w-0">
        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
            {{ $record['name'] ?? $record['email'] }}
        </p>

        @if ($record['subtitle'] !== null)
            <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                {{ $record['subtitle'] }}
            </p>
        @endif
    </div>
</div>
