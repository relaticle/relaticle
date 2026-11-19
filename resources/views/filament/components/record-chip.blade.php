@props(['chip'])
@php
    $box = match ($chip->size) {
        'lg' => 'size-10',
        'md' => 'size-6',
        default => 'size-5',
    };

    $glyph = match ($chip->size) {
        'lg' => 'size-5',
        'md' => 'size-3.5',
        default => 'size-3',
    };

    $shape = $chip->circular ? 'rounded-full' : 'rounded';
@endphp
<span class="fi-record-chip inline-flex min-w-0 max-w-full items-center gap-2 align-middle" @if (filled($chip->imageUrl) && filled($chip->iconPath)) x-data="{ broken: false }" x-init="if ($refs.logo.complete && $refs.logo.naturalWidth === 0) broken = true" @endif>@if (filled($chip->imageUrl))<img
        src="{{ $chip->imageUrl }}"
        alt=""
        @if (filled($chip->iconPath)) x-ref="logo" x-show="! broken" x-on:error="broken = true" @endif
        @class(['shrink-0 object-cover ring-1 ring-gray-950/5 dark:ring-white/10', $box, $shape])
    />@endif@if (filled($chip->iconPath))<span @if (filled($chip->imageUrl)) x-show="broken" style="display: none" @endif @class([
        'flex shrink-0 items-center justify-center bg-gray-100 text-gray-600 ring-1 ring-gray-950/5',
        'dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
        $box,
        $shape,
    ])><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" @class([$glyph]) aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chip->iconPath }}" /></svg></span>@endif<span class="truncate">{{ $chip->name }}</span></span>
