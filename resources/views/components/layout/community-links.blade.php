@props([
    'variant' => 'segmented',
])

@php
    $links = [
        [
            'url' => 'https://github.com/relaticle/relaticle',
            'icon' => 'ri-github-fill',
            'count' => $githubStars > 0 ? $formattedGithubStars : null,
            'label' => $githubStars > 0
                ? __('GitHub, :count stars', ['count' => $formattedGithubStars])
                : __('GitHub'),
        ],
        [
            'url' => route('discord'),
            'icon' => 'ri-discord-fill',
            'count' => $formattedDiscordMembers,
            'label' => $formattedDiscordMembers !== null
                ? __('Discord, :count members', ['count' => $formattedDiscordMembers])
                : __('Discord'),
        ],
    ];
@endphp

@if($variant === 'nav')
    <div {{ $attributes->class('items-center') }}>
        <span aria-hidden="true" class="ml-4 mr-2 h-4 w-px bg-gray-200 dark:bg-white/10"></span>
        @foreach($links as $link)
            @unless($loop->first)
                <span aria-hidden="true" class="h-4 w-px bg-gray-200 dark:bg-white/10"></span>
            @endunless
            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
               aria-label="{{ $link['label'] }}" title="{{ $link['label'] }}"
               class="group inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[13px] font-medium text-gray-600 transition-colors duration-200 hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-gray-400 dark:hover:bg-white/[0.08] dark:hover:text-white">
                <x-dynamic-component :component="$link['icon']" class="h-4 w-4 shrink-0" aria-hidden="true"/>
                @if($link['count'] !== null)
                    <span class="tabular-nums text-gray-900 dark:text-white">{{ $link['count'] }}</span>
                @endif
                <x-ri-arrow-right-up-line class="h-3 w-3 shrink-0 text-gray-400 transition-[translate,color] duration-200 group-hover:translate-x-px group-hover:-translate-y-px group-hover:text-gray-600 motion-reduce:transition-none dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true"/>
            </a>
        @endforeach
    </div>
@else
    <div {{ $attributes->class('h-8 items-stretch divide-x divide-gray-200/80 overflow-hidden rounded-full border border-gray-200/80 bg-white/60 dark:divide-white/[0.08] dark:border-white/[0.08] dark:bg-white/[0.03]') }}>
        @foreach($links as $link)
            <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer"
               aria-label="{{ $link['label'] }}" title="{{ $link['label'] }}"
               class="group inline-flex items-center gap-1.5 px-3 text-[12px] font-semibold text-gray-900 transition-colors hover:bg-gray-100/80 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary dark:text-white dark:hover:bg-white/[0.06]">
                <x-dynamic-component :component="$link['icon']" class="h-4 w-4 shrink-0 text-gray-400 transition-colors group-hover:text-gray-900 dark:text-gray-500 dark:group-hover:text-white" aria-hidden="true"/>
                @if($link['count'] !== null)
                    <span class="tabular-nums">{{ $link['count'] }}</span>
                @endif
                <x-ri-arrow-right-up-line class="h-3 w-3 shrink-0 text-gray-400 transition-[translate,color] duration-200 group-hover:translate-x-px group-hover:-translate-y-px group-hover:text-gray-600 motion-reduce:transition-none dark:text-gray-500 dark:group-hover:text-gray-300" aria-hidden="true"/>
            </a>
        @endforeach
    </div>
@endif
