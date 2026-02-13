@php
    $record = $getRecord();
    $name = $record->name ?? '';
    $avatarUrl = $record->avatar;
@endphp

<div class="flex items-center gap-2">
    <img
        src="{{ $avatarUrl }}"
        alt=""
        class="size-5 rounded-full border border-neutral-200 shrink-0"
    />
    <span class="truncate">{{ $name }}</span>
</div>
