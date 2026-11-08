@props([
    'align' => 'center',
    'layout' => 'stack',
    'glow' => false,
])

<section {{ $attributes->class(['bg-surface border-rail border-b', 'overflow-x-clip' => $layout === 'split']) }}>
    <div class="border-rail mx-auto max-w-6xl sm:border-x">
        @if($layout === 'stage')
            <div class="px-3 py-3 sm:px-6 sm:py-6">
                <x-cloud::marketing.stage light="inside" class="bg-tint rounded-3xl">
                    <x-cloud::marketing.hero-copy align="start" wide :eyebrow="$eyebrow ?? null" :title="$title" :aside="$aside ?? null" class="px-6 pt-10 sm:px-10 md:px-14 md:pt-14"/>

                    {{ $visual ?? '' }}
                </x-cloud::marketing.stage>
            </div>
        @elseif($layout === 'split')
            <div class="grid items-center gap-x-12 px-6 pb-10 pt-9 md:px-10 md:pb-12 md:pt-12 lg:grid-cols-[minmax(0,1fr)_34rem]">
                <x-cloud::marketing.hero-copy align="start" :eyebrow="$eyebrow ?? null" :title="$title" :aside="$aside ?? null"/>

                <div>{{ $visual ?? '' }}</div>
            </div>
        @else
            <x-cloud::marketing.hero-copy :align="$align" :glow="$glow" :eyebrow="$eyebrow ?? null" :title="$title" :aside="$aside ?? null" class="px-6 pb-10 pt-9 md:px-10 md:pb-12 md:pt-12"/>

            {{ $slot }}

            @isset($visual)
                <div class="px-3 pb-3 sm:px-6 sm:pb-6">{{ $visual }}</div>
            @endisset
        @endif
    </div>
</section>
