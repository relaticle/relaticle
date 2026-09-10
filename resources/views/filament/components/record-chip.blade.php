@props(['chip'])
@php
    $avatarClasses = match ($chip->size) {
        'lg' => 'size-10',
        'md' => 'size-6',
        default => 'size-5',
    };
@endphp
<span class="inline-flex min-w-0 items-center gap-2 align-middle">@if (filled($chip->avatarUrl))<img
        src="{{ $chip->avatarUrl }}"
        alt=""
        @class([
            'shrink-0 object-cover ring-1 ring-gray-950/5 dark:ring-white/10',
            $avatarClasses,
            'rounded-full' => $chip->circular,
            'rounded' => ! $chip->circular,
        ])
    />@endif<span class="truncate">{{ $chip->name }}</span></span>
