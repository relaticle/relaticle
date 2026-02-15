@php
    $record = $getRecord();
    $name = $record->name ?? '';
    $avatarUrl = $record->avatar;
@endphp

<div class="flex items-center gap-2 px-3 py-2">
    <img
        src="{{ $avatarUrl }}"
        alt=""
        class="size-5 rounded-full ring-1 ring-gray-200 dark:ring-gray-700 shrink-0"
    />
    <span class="truncate">{{ $name }}</span>
</div>
