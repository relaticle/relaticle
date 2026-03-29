@props([
    'variant' => 'primary',
    'size' => 'base',
    'href' => null,
    'icon' => null,
    'iconTrailing' => null,
    'external' => false,
])

@php
    $tag = $href ? 'a' : 'button';

    $sizes = [
        'sm' => 'h-9 px-5 text-sm rounded-lg',
        'base' => 'h-11 px-8 text-sm rounded-lg',
    ];

    $variants = [
        'primary' => 'bg-primary font-semibold text-white shadow-[0_1px_2px_rgba(0,0,0,0.1),inset_0_1px_0_rgba(255,255,255,0.1)] hover:brightness-110',
        'secondary' => 'border border-gray-200 bg-white text-gray-700 shadow-[0_1px_2px_rgba(0,0,0,0.04)] hover:border-gray-300 hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-white dark:hover:bg-white/[0.06] dark:hover:border-white/[0.15]',
    ];
@endphp

<{{ $tag }} {{ $attributes
    ->class([
        'inline-flex items-center justify-center gap-2 cursor-pointer font-medium transition-all duration-200',
        $sizes[$size] ?? $sizes['base'],
        $variants[$variant] ?? $variants['primary'],
    ])
    ->merge(array_filter([
        'href' => $href,
        'target' => $external ? '_blank' : null,
        'rel' => $external ? 'noopener' : null,
        'type' => ! $href ? 'button' : null,
    ]))
}}>
    @if($icon)
        <x-dynamic-component :component="$icon" class="h-4 w-4"/>
    @endif

    {{ $slot }}

    @if($iconTrailing)
        <x-dynamic-component :component="$iconTrailing" class="h-3.5 w-3.5"/>
    @endif

    @if($external && ! $iconTrailing)
        <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-500"/>
    @endif
</{{ $tag }}>
