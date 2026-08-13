@php
    $baseTitle = __('Help Centre');
    $pageDescription = __('Guides for setting up your workspace, importing records, and using Relaticle day to day.');
@endphp

<x-documentation::shell
    :title="$baseTitle . ' - ' . config('app.name')"
    :description="$pageDescription"
    :nav="$nav">
    <div class="mx-auto max-w-3xl">
        <p class="text-pico font-semibold tracking-[0.08em] text-primary-600 uppercase dark:text-primary-400">
            {{ __('Relaticle docs') }}
        </p>
        <h1 class="font-display mt-3 text-4xl font-bold tracking-[-0.02em] text-gray-950 sm:text-[2.75rem] dark:text-white">
            {{ __('How can we help?') }}
        </h1>
        <p class="mt-4 max-w-2xl text-lg leading-relaxed text-gray-500 dark:text-gray-400">
            {{ $pageDescription }}
        </p>

        <button type="button"
                x-on:click="openSearch()"
                x-on:mouseenter="warm()"
                x-on:focus="warm()"
                class="mt-8 flex w-full cursor-pointer items-center gap-3 rounded-xl border border-[var(--surface-input-border)] bg-[var(--surface-input-bg)] px-4 py-3.5 text-left text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-white/15 dark:hover:text-gray-200">
            <x-ri-search-line class="h-5 w-5 shrink-0 text-gray-400" />
            <span class="flex-1 text-sm sm:text-base">{{ __('Search help articles and guides') }}</span>
            <kbd class="hidden rounded border border-gray-200 bg-white px-2 py-1 font-sans text-xs font-medium text-gray-500 sm:block dark:border-white/10 dark:bg-white/5 dark:text-gray-400">⌘K</kbd>
        </button>
    </div>

    <div class="mx-auto mt-16 max-w-5xl space-y-14">
        @foreach($nav as $section)
            <section aria-labelledby="section-{{ str_replace('/', '-', $section['path']) }}">
                <div class="flex items-start gap-3.5">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-documentation::doc-icon :topic="$section['path']" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="section-{{ str_replace('/', '-', $section['path']) }}" class="font-display text-lg font-semibold tracking-tight text-gray-950 dark:text-white">
                            <a href="{{ $section['url'] }}" class="transition-colors hover:text-primary-600 dark:hover:text-primary-400">{{ $section['title'] }}</a>
                        </h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $section['description'] }}</p>
                    </div>
                    <span class="ml-auto hidden shrink-0 pt-1 text-xs text-gray-400 sm:block dark:text-gray-500">
                        {{ trans_choice('{1}:count article|[2,*]:count articles', count($section['links']), ['count' => count($section['links'])]) }}
                    </span>
                </div>

                <ul class="mt-5 grid gap-px overflow-hidden rounded-xl border border-gray-200/80 bg-gray-200/80 sm:grid-cols-2 dark:border-white/[0.06] dark:bg-white/[0.06]">
                    @foreach($section['links'] as $link)
                        <li class="bg-white dark:bg-gray-950">
                            <a href="{{ $link['url'] }}" class="group flex h-full items-start gap-3 p-5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                <span class="min-w-0 flex-1">
                                    <span class="font-display block text-sm font-semibold tracking-tight text-gray-900 transition-colors group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                                        {{ $link['title'] }}
                                    </span>
                                    <span class="mt-1 block text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                        {{ $link['description'] }}
                                    </span>
                                </span>
                                <x-ri-arrow-right-line class="mt-0.5 h-4 w-4 shrink-0 text-gray-300 transition-all group-hover:translate-x-0.5 group-hover:text-primary-500 dark:text-gray-600" />
                            </a>
                        </li>
                    @endforeach
                    @if(count($section['links']) % 2 === 1)
                        {{-- Keeps the gap-px divider grid from showing a bare
                             cell when a section has an odd number of links. --}}
                        <li class="hidden bg-white sm:block dark:bg-gray-950" aria-hidden="true"></li>
                    @endif
                </ul>
            </section>
        @endforeach

        <section class="rounded-xl border border-gray-200/80 bg-[var(--surface-card-bg)] p-6 sm:flex sm:items-center sm:justify-between sm:gap-6 dark:border-white/[0.06]">
            <div>
                <h2 class="font-display text-base font-semibold tracking-tight text-gray-950 dark:text-white">{{ __('Still stuck?') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Ask the team and other Relaticle users in Discord, or open an issue on GitHub.') }}
                </p>
            </div>
            <div class="mt-4 flex shrink-0 flex-wrap gap-2 sm:mt-0">
                <x-marketing.button variant="secondary" size="sm" href="{{ route('discord') }}" icon="ri-discord-fill" :external="true">
                    {{ __('Join Discord') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" size="sm" href="https://github.com/Relaticle/relaticle/issues" icon="ri-github-fill" :external="true">
                    {{ __('Open an issue') }}
                </x-marketing.button>
            </div>
        </section>
    </div>

    @php
        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->breadcrumbs([
            ['name' => $baseTitle, 'url' => route('help.index')],
        ]);
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
