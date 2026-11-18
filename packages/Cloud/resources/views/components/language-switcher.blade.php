@php
    $current = app()->getLocale();
    $languages = \Relaticle\Cloud\Marketing\Support\LocalizedUrl::languages();
@endphp

<div data-language-switcher
     x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:click.outside="open = false"
     x-on:focusout="if (! $el.contains($event.relatedTarget)) open = false"
     {{ $attributes->class('relative') }}>
    <button type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
            aria-controls="language-list"
            class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-lg px-2.5 text-sm text-gray-500 transition-colors hover:bg-tint hover:text-gray-950 dark:text-gray-400 dark:hover:text-white">
        <x-lucide-globe class="size-menu-icon" />
        <span>{{ $languages[$current]['native'] ?? $languages[\Relaticle\Cloud\Marketing\Support\LocalizedUrl::DEFAULT_LOCALE]['native'] }}</span>
    </button>

    <nav id="language-list"
         aria-label="{{ __('Language') }}"
         x-cloak
         x-show="open"
         x-transition.opacity.duration.100ms
         class="absolute bottom-full end-0 z-50 mb-2 max-h-menu-max w-64 max-w-[calc(100vw-3rem)] overflow-y-auto rounded-menu border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-900">
        @foreach($languages as $code => $names)
            <a href="{{ \Relaticle\Cloud\Marketing\Support\LocalizedUrl::current($code) }}"
               hreflang="{{ $code }}"
               @if($code === $current) aria-current="true" @endif
               class="flex h-menu-row items-center gap-3 rounded-menu-item px-2.5 text-sm text-gray-950 transition-colors hover:bg-gray-100 dark:text-white dark:hover:bg-white/5">
                <span lang="{{ $code }}" class="min-w-0 flex-1 truncate">{{ $names['native'] }}</span>
                <span class="shrink-0 text-gray-500 dark:text-gray-400">{{ $names['english'] }}</span>
                <span class="size-menu-icon shrink-0">
                    @if($code === $current)
                        <x-lucide-check class="size-menu-icon" />
                    @endif
                </span>
            </a>
        @endforeach
    </nav>
</div>
