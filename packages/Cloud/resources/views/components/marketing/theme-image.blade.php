@props([
    'name',
    'alt',
    'widths' => [640, 832, 1652],
    'sizes' => '100vw',
    'width' => 1440,
    'height' => 1116,
])

@php
    $srcset = static fn (string $theme): string => collect($widths)
        ->map(fn (int $width): string => Vite::asset("resources/images/{$name}{$theme}-{$width}w.webp")." {$width}w")
        ->push(Vite::asset("resources/images/{$name}{$theme}.webp").' 2880w')
        ->implode(', ');
@endphp

<img
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    x-init="new MutationObserver(() => dark = document.documentElement.classList.contains('dark')).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })"
    :srcset="dark ? @js($srcset('-dark')) : @js($srcset(''))"
    src="{{ Vite::asset("resources/images/{$name}-1652w.webp") }}"
    srcset="{{ $srcset('') }}"
    sizes="{{ $sizes }}"
    alt="{{ $alt }}"
    width="{{ $width }}"
    height="{{ $height }}"
    loading="lazy"
    decoding="async"
    {{ $attributes }}
>
