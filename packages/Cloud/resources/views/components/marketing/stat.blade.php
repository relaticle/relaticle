@props([
    'value',
    'label',
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href) href="{{ $href }}" @endif
    {{ $attributes->class(['bg-surface flex flex-col gap-2 p-6 md:p-8', 'hover:bg-tint transition-colors' => $href]) }}
>
    <span class="type-title text-gray-950 dark:text-white">{{ $value }}</span>
    <span class="type-label text-gray-500 dark:text-gray-400">{{ $label }}</span>
</{{ $tag }}>
