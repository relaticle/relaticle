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
    $chipClass = 'fi-record-chip inline-flex w-fit min-w-0 max-w-full items-center gap-2 align-middle';
@endphp
@if (filled($chip->url))
    <a {{ \Filament\Support\generate_href_html($chip->url, $chip->openUrlInNewTab, shouldOpenInSpaMode: null, hasNestedClickEventHandler: true) }} class="{{ $chipClass }}">
@else
    <span class="{{ $chipClass }}">
@endif
    @if (filled($chip->imageUrl))
        <img
            src="{{ $chip->imageUrl }}"
            alt=""
            @class(['shrink-0 object-cover ring-1 ring-gray-950/5 dark:ring-white/10', $box, $shape])
        />
    @elseif (filled($chip->iconPath))
        <span @class([
            'flex shrink-0 items-center justify-center bg-gray-100 text-gray-600 ring-1 ring-gray-950/5',
            'dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
            $box,
            $shape,
        ])><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" @class([$glyph]) aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $chip->iconPath }}" /></svg></span>
    @endif
    <span @class(['truncate' => $chip->size !== 'lg']) title="{{ $chip->name }}">{{ $chip->name }}</span>
@if (filled($chip->url))
    </a>
@else
    </span>
@endif
