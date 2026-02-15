@php
    $record = $getRecord();
    $name = $record->name ?? '';
    $logoUrl = $record->logo;
@endphp

<div class="flex items-center gap-2 px-3 py-2">
    <img
        src="{{ $logoUrl }}"
        alt=""
        class="size-5 rounded ring-1 ring-gray-200 dark:ring-gray-700 shrink-0"
    />
    <span class="truncate">{{ $name }}</span>
</div>
