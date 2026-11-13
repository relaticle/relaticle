@props([
    'logos' => [],
])

@if($logos !== [])
    <ul {{ $attributes->class('bg-rail grid grid-cols-2 gap-px sm:grid-cols-3 md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]') }}>
        @foreach($logos as $logo)
            @php
                $href = $logo['url'] ?? null;
                $tag = $href ? 'a' : 'span';
            @endphp

            <li class="bg-surface flex">
                <{{ $tag }}
                    @if($href) href="{{ $href }}" target="_blank" rel="noopener" @endif
                    @class(['flex flex-1 items-center justify-center px-6 py-8', 'hover:bg-tint transition-colors' => $href])
                >
                    <img
                        src="{{ Str::isUrl($logo['src']) ? $logo['src'] : Vite::asset($logo['src']) }}"
                        alt="{{ $logo['name'] }}"
                        width="120"
                        height="40"
                        loading="lazy"
                        class="h-6 w-auto"
                    />
                </{{ $tag }}>
            </li>
        @endforeach
    </ul>
@endif
