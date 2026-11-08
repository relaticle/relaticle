@props([
    'texture' => null,
])

@php
    $textureClass = match ($texture) {
        'wires' => 'texture-wires',
        'crosses' => 'texture-crosses',
        'rows' => 'texture-rows',
        null => null,
    };
@endphp

<div {{ $attributes->class('border-rail relative border-t') }}>
    @if($textureClass)
        @foreach(['left-0 -scale-x-100', 'right-0'] as $side)
            <div class="mask-gutter pointer-events-none absolute inset-y-0 {{ $side }} w-[calc(50%-var(--container-6xl)/2)]" aria-hidden="true">
                <div class="{{ $textureClass }} size-full"></div>
            </div>
        @endforeach
    @endif

    <div class="border-rail relative mx-auto max-w-6xl sm:border-x">
        {{ $slot }}
    </div>
</div>
