@props([
    'page',
    'body',
    'headings' => [],
    'previous' => null,
    'next' => null,
    'related' => null,
    'eyebrow' => null,
    'eyebrowUrl' => null,
])

@php
    $related ??= collect();
    $markdownUrl = \Relaticle\Documentation\Support\DocUrl::markdown($page);
    $hasToc = count($headings) >= 2;
@endphp

<div class="flex gap-12">
    <div class="min-w-0 max-w-[45rem] flex-1">
        <div class="flex items-start justify-between gap-6">
            <div class="min-w-0">
                @if($eyebrow)
                    <p class="text-pico font-semibold tracking-[0.08em] text-primary-600 uppercase dark:text-primary-400">
                        @if($eyebrowUrl)
                            <a href="{{ $eyebrowUrl }}" class="transition-opacity hover:opacity-70">{{ $eyebrow }}</a>
                        @else
                            {{ $eyebrow }}
                        @endif
                    </p>
                @endif
                <h1 class="font-display mt-2.5 text-[2rem] font-bold tracking-[-0.02em] text-balance text-gray-950 sm:text-[2.25rem] dark:text-white">
                    {{ $page->title }}
                </h1>
            </div>

            {{-- A landmark so the markdown response drops it: agents fetching
                 the .md variant have no use for its own download controls. --}}
            <nav aria-label="{{ __('Page actions') }}"
                 x-data="{
                     open: false,
                     copied: false,
                     async copyMarkdown() {
                         this.open = false;
                         try {
                             const markdown = await (await fetch('{{ $markdownUrl }}')).text();
                             await navigator.clipboard.writeText(markdown);
                             this.copied = true;
                             setTimeout(() => this.copied = false, 2000);
                         } catch {}
                     },
                 }"
                 x-on:click.outside="open = false"
                 x-on:keydown.escape.stop="open = false"
                 class="relative mt-1 hidden shrink-0 sm:block">
                <div class="flex items-center rounded-lg border border-gray-200 dark:border-white/10">
                    <button type="button"
                            x-on:click="copyMarkdown()"
                            class="flex cursor-pointer items-center gap-1.5 rounded-l-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white">
                        <x-ri-file-copy-line x-show="!copied" class="h-3.5 w-3.5" />
                        <x-ri-check-line x-cloak x-show="copied" class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy page') }}'"></span>
                    </button>
                    <button type="button"
                            x-on:click="open = !open"
                            x-bind:aria-expanded="open"
                            class="cursor-pointer rounded-r-lg border-l border-gray-200 px-1.5 py-1.5 text-gray-500 transition-colors hover:bg-gray-50 hover:text-gray-900 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                            aria-label="{{ __('More page actions') }}">
                        <x-ri-arrow-down-s-line class="h-3.5 w-3.5" />
                    </button>
                </div>

                <div x-cloak
                     x-show="open"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 -translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="absolute right-0 z-20 mt-1.5 w-52 overflow-hidden rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-900">
                    <button type="button"
                            x-on:click="copyMarkdown()"
                            class="flex w-full cursor-pointer items-center gap-2 px-3 py-2 text-left text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                        <x-ri-file-copy-line class="h-3.5 w-3.5" />
                        {{ __('Copy as Markdown') }}
                    </button>
                    <a href="{{ $markdownUrl }}"
                       class="flex w-full items-center gap-2 px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5">
                        <x-ri-markdown-line class="h-3.5 w-3.5" />
                        {{ __('View as Markdown') }}
                    </a>
                </div>
            </nav>
        </div>

        @if(filled($page->description))
            <p class="mt-3.5 text-[17px] leading-relaxed text-gray-500 dark:text-gray-400">{{ $page->description }}</p>
        @endif

        @if($page->updated)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                {{ __('Updated :date', ['date' => $page->updated->isoFormat('MMMM D, YYYY')]) }}
            </p>
        @endif

        @if($hasToc)
            <nav aria-label="{{ __('On this page') }}" class="mt-7 xl:hidden">
                <details class="rounded-xl border border-gray-200 dark:border-white/10">
                    <summary class="cursor-pointer rounded-xl px-4 py-3 text-[13px] font-medium text-gray-700 select-none dark:text-gray-300">
                        {{ __('On this page') }}
                    </summary>
                    <ul class="space-y-1 border-t border-gray-100 px-4 py-3 text-[13px] dark:border-white/5">
                        @foreach($headings as $heading)
                            <li>
                                <a href="#{{ $heading['anchor'] }}" class="block py-1 text-gray-600 transition-colors hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
                                    {{ $heading['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </details>
            </nav>
        @endif

        <article id="documentation-content" class="prose-docs mt-9">{!! $body !!}</article>

        @if($related->isNotEmpty())
            <nav aria-label="{{ __('Related articles') }}" class="mt-14 border-t border-gray-200/80 pt-8 dark:border-white/[0.06]">
                <h2 class="text-pico font-semibold tracking-[0.08em] text-gray-400 uppercase dark:text-gray-500">{{ __('Keep reading') }}</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach($related as $relatedPage)
                        <a href="{{ \Relaticle\Documentation\Support\DocUrl::page($relatedPage) }}"
                           class="group rounded-xl border border-gray-200/80 p-4 transition-colors hover:border-primary-300 hover:bg-gray-50/60 dark:border-white/[0.06] dark:hover:border-primary-500/40 dark:hover:bg-white/[0.02]">
                            <span class="font-display block text-[13px] font-semibold tracking-tight text-gray-900 transition-colors group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                                {{ $relatedPage->title }}
                            </span>
                            <span class="mt-1 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $relatedPage->description }}</span>
                        </a>
                    @endforeach
                </div>
            </nav>
        @endif

        @if($previous || $next)
            <nav aria-label="{{ __('Article pagination') }}" class="mt-10 grid gap-3 border-t border-gray-200/80 pt-8 sm:grid-cols-2 dark:border-white/[0.06]">
                @if($previous)
                    <a href="{{ \Relaticle\Documentation\Support\DocUrl::page($previous) }}"
                       class="group rounded-xl border border-gray-200/80 p-4 transition-colors hover:border-gray-300 dark:border-white/[0.06] dark:hover:border-white/15">
                        <span class="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                            <x-ri-arrow-left-line class="h-3.5 w-3.5 transition-transform group-hover:-translate-x-0.5" />
                            {{ __('Previous') }}
                        </span>
                        <span class="font-display mt-1 block text-[13px] font-semibold tracking-tight text-gray-900 dark:text-white">{{ $previous->title }}</span>
                    </a>
                @else
                    <span class="hidden sm:block"></span>
                @endif

                @if($next)
                    <a href="{{ \Relaticle\Documentation\Support\DocUrl::page($next) }}"
                       class="group rounded-xl border border-gray-200/80 p-4 text-right transition-colors hover:border-gray-300 sm:col-start-2 dark:border-white/[0.06] dark:hover:border-white/15">
                        <span class="flex items-center justify-end gap-1.5 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('Next') }}
                            <x-ri-arrow-right-line class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                        </span>
                        <span class="font-display mt-1 block text-[13px] font-semibold tracking-tight text-gray-900 dark:text-white">{{ $next->title }}</span>
                    </a>
                @endif
            </nav>
        @endif

        <div class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-gray-200/80 pt-6 text-[13px] dark:border-white/[0.06]">
            <a href="https://github.com/Relaticle/relaticle/edit/main/packages/Documentation/resources/content/{{ $page->path }}.md"
               target="_blank"
               rel="noopener noreferrer"
               class="inline-flex items-center gap-2 text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                <x-ri-github-fill class="h-4 w-4" />
                {{ __('Edit this page on GitHub') }}
            </a>
            <a href="{{ route('discord') }}"
               target="_blank"
               rel="noopener noreferrer"
               class="inline-flex items-center gap-2 text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                <x-ri-discord-fill class="h-4 w-4" />
                {{ __('Ask a question in Discord') }}
            </a>
        </div>
    </div>

    @if($hasToc)
        <aside class="hidden w-56 shrink-0 xl:block">
            <nav aria-label="{{ __('On this page') }}" class="sticky top-[4.5rem] text-[13px]">
                <h2 class="text-pico font-semibold tracking-[0.08em] text-gray-400 uppercase dark:text-gray-500">{{ __('On this page') }}</h2>
                <ul id="docs-toc" class="mt-3 space-y-0.5 border-l border-gray-200 dark:border-white/10">
                    @foreach($headings as $heading)
                        <li>
                            <a href="#{{ $heading['anchor'] }}" class="docs-toc-link -ml-px block border-l border-transparent py-1 pl-3.5 transition-colors">
                                {{ $heading['text'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>
    @endif
</div>
