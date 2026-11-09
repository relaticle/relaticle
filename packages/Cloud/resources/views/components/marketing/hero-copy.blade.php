@props([
    'align' => 'center',
    'eyebrow' => null,
    'title',
    'aside' => null,
    'glow' => false,
    'wide' => false,
])

<div {{ $attributes->class(['relative isolate' => $glow, 'flex flex-col items-center text-center' => $align === 'center']) }}>
    @if($glow)
        <div class="glow-indigo pointer-events-none absolute bottom-0 left-1/2 -z-10 h-48 w-[44rem] max-w-full -translate-x-1/2 translate-y-1/2 rounded-full blur-3xl" aria-hidden="true"></div>
    @endif

    @if($eyebrow !== null)
        <div class="mb-6">{{ $eyebrow }}</div>
    @endif

    <h1 @class(['type-display text-gray-950 dark:text-white', 'max-w-3xl' => $align === 'center', 'max-w-4xl' => $align === 'start'])>{{ $title }}</h1>

    @if($aside !== null)
        <div @class([
            'mt-5 flex flex-col',
            'max-w-2xl' => ! $wide,
            'w-full' => $align === 'center' || $wide,
            'items-center' => $align === 'center',
            'items-start' => $align === 'start',
        ])>{{ $aside }}</div>
    @endif
</div>
