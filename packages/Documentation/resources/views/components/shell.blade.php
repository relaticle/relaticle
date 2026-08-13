@props([
    'title',
    'description',
    'ogTitle' => null,
    'ogDescription' => null,
    'nav' => [],
    'currentPath' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-layout.head
        :title="$title"
        :description="$description"
        :og-title="$ogTitle ?? $title"
        :og-description="$ogDescription ?? $description" />

    {{-- documentation.js before app.js on purpose: app.js calls Alpine.start()
         as it executes, and the shell's x-data reaches for window.RelaticleDocs
         the moment Alpine initialises. --}}
    @vite([
        'resources/css/app.css',
        'packages/Documentation/resources/css/documentation.css',
        'packages/Documentation/resources/js/documentation.js',
        'resources/js/app.js',
    ])

    @if(app()->isProduction() && !empty(config('services.fathom.site_id')))
        <script src="https://cdn.usefathom.com/script.js" data-site="{{ config('services.fathom.site_id') }}" defer></script>
    @endif
</head>
<body class="min-h-screen bg-white font-sans text-gray-700 antialiased dark:bg-gray-950 dark:text-gray-300">

<div
    x-data="{
        sidebarOpen: false,
        searchOpen: false,
        returnFocus: null,
        query: '',
        records: null,
        active: 0,
        warmPromise: null,
        warm() {
            return this.warmPromise ??= (window.RelaticleDocs?.loadIndex('{{ route('help.search-index') }}') ?? Promise.reject())
                .then((records) => { this.records = records; })
                .catch(() => { this.warmPromise = null; });
        },
        openSearch() {
            this.returnFocus = document.activeElement;
            this.searchOpen = true;
            this.query = '';
            this.active = 0;
            this.warm();
            this.$nextTick(() => this.$refs.searchInput?.focus());
        },
        closeSearch() {
            this.searchOpen = false;
            this.returnFocus?.focus?.();
            this.returnFocus = null;
        },
        /* Alpine leaves plain getters unmemoized and every template reference is
           its own reactive effect, so an unguarded getter re-runs the whole
           search once per binding per keystroke. The cache lives in a closure to
           stay outside the reactive graph. */
        memo: (() => {
            let deps = null;
            let value = [];

            return (next, compute) => {
                if (deps === null || next.some((dep, index) => dep !== deps[index])) {
                    deps = next;
                    value = compute();
                }

                return value;
            };
        })(),
        get results() {
            return this.memo([this.query, this.records], () => window.RelaticleDocs?.search(this.records, this.query) ?? []);
        },
        move(delta) {
            if (this.results.length === 0) return;
            this.active = (this.active + delta + this.results.length) % this.results.length;
            this.$refs.searchResults?.children[this.active]?.scrollIntoView({ block: 'nearest' });
        },
        go() {
            const result = this.results[this.active];
            if (result) window.location.href = result.url;
        },
    }"
    x-init="('requestIdleCallback' in window) ? requestIdleCallback(() => warm(), { timeout: 3000 }) : setTimeout(() => warm(), 2000)"
    x-effect="document.body.style.overflow = (sidebarOpen || searchOpen) ? 'hidden' : ''"
    x-on:keydown.cmd.k.window.prevent="openSearch()"
    x-on:keydown.ctrl.k.window.prevent="openSearch()"
    x-on:keydown.escape.window="searchOpen ? closeSearch() : (sidebarOpen = false)"
>
    <nav aria-label="{{ __('Skip links') }}">
        <a href="#docs-content"
           class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-gray-900 focus:shadow-lg focus:ring-2 focus:ring-primary dark:focus:bg-gray-900 dark:focus:text-white">
            {{ __('Skip to content') }}
        </a>
    </nav>

    <header class="sticky top-0 z-40 border-b border-gray-200/70 bg-white/85 backdrop-blur-md dark:border-white/[0.06] dark:bg-gray-950/85">
        <div class="flex h-14 items-center gap-3 px-4 sm:px-6">
            <button type="button"
                    x-on:click="sidebarOpen = true"
                    class="-ml-1.5 rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 lg:hidden dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                    aria-label="{{ __('Open navigation') }}">
                <x-ri-menu-line class="h-5 w-5" />
            </button>

            <a href="{{ url('/') }}" class="flex shrink-0 items-center gap-2.5" aria-label="{{ __('Relaticle home') }}">
                <x-brand.logomark size="sm" />
                <span class="hidden items-center gap-2.5 sm:flex">
                    <span class="font-display text-[15px] font-bold tracking-tight text-gray-900 dark:text-white">Relaticle</span>
                    <span aria-hidden="true" class="h-3.5 w-px bg-gray-200 dark:bg-white/15"></span>
                </span>
            </a>
            <a href="{{ route('help.index') }}"
               class="font-display text-[15px] font-semibold tracking-tight text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                {{ __('Docs') }}
            </a>

            <div class="ml-auto flex items-center gap-2">
                <button type="button"
                        x-on:click="openSearch()"
                        x-on:mouseenter="warm()"
                        x-on:focus="warm()"
                        class="hidden w-60 cursor-pointer items-center gap-2.5 rounded-lg border border-[var(--surface-input-border)] bg-[var(--surface-input-bg)] px-3 py-1.5 text-left text-[13px] text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-700 md:flex dark:text-gray-400 dark:hover:border-white/15 dark:hover:text-gray-200">
                    <x-ri-search-line class="h-4 w-4 shrink-0" />
                    <span class="flex-1">{{ __('Search the docs') }}</span>
                    <kbd class="rounded border border-gray-200 bg-white px-1.5 font-sans text-pico font-medium text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">⌘K</kbd>
                </button>
                <button type="button"
                        x-on:click="openSearch()"
                        class="rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 md:hidden dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                        aria-label="{{ __('Search the docs') }}">
                    <x-ri-search-line class="h-5 w-5" />
                </button>

                <x-theme-switcher />

                <span aria-hidden="true" class="mx-1 hidden h-5 w-px bg-gray-200 sm:block dark:bg-white/10"></span>

                <a href="{{ route('login') }}"
                   class="hidden rounded-lg px-2 py-1.5 text-[13px] font-medium text-gray-600 transition-colors hover:text-gray-900 sm:block dark:text-gray-400 dark:hover:text-white">
                    {{ __('Sign in') }}
                </a>
                <x-marketing.button size="sm" href="{{ route('register') }}" class="whitespace-nowrap">
                    {{ __('Start for free') }}
                </x-marketing.button>
            </div>
        </div>
    </header>

    {{-- Mobile navigation drawer --}}
    <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="{{ __('Browse the docs') }}">
        <div x-show="sidebarOpen" x-transition.opacity.duration.200ms class="fixed inset-0 bg-gray-950/40 dark:bg-black/60" x-on:click="sidebarOpen = false"></div>
        <nav aria-label="{{ __('Documentation navigation') }}"
             x-show="sidebarOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="-translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="-translate-x-full"
             class="docs-scroll fixed inset-y-0 left-0 w-[17.5rem] overflow-y-auto border-r border-gray-200 bg-white px-6 py-5 dark:border-white/[0.06] dark:bg-gray-950">
            <div class="mb-7 flex items-center justify-between">
                <span class="font-display text-sm font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('Browse the docs') }}</span>
                <button type="button"
                        x-on:click="sidebarOpen = false"
                        class="-mr-1.5 rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white"
                        aria-label="{{ __('Close navigation') }}">
                    <x-ri-close-line class="h-5 w-5" />
                </button>
            </div>
            <x-documentation::sidebar-nav :nav="$nav" :current-path="$currentPath" />
        </nav>
    </div>

    <div class="mx-auto flex w-full max-w-[100rem]">
        <aside class="sticky top-14 hidden h-[calc(100vh-3.5rem)] w-64 shrink-0 border-r border-gray-200/70 lg:block dark:border-white/[0.06]">
            <nav aria-label="{{ __('Documentation navigation') }}" class="docs-scroll h-full overflow-y-auto px-6 py-9">
                <x-documentation::sidebar-nav :nav="$nav" :current-path="$currentPath" />
            </nav>
        </aside>

        <div class="min-w-0 flex-1">
            <main id="docs-content" tabindex="-1" class="px-5 py-9 sm:px-8 lg:px-12 lg:py-12">
                @isset($breadcrumbs)
                    <nav aria-label="{{ __('Breadcrumb') }}" class="mb-7 text-[13px] text-gray-500 dark:text-gray-400">
                        {{ $breadcrumbs }}
                    </nav>
                @endisset

                {{ $slot }}
            </main>

            <footer class="border-t border-gray-200/70 px-5 py-8 sm:px-8 lg:px-12 dark:border-white/[0.06]">
                <div class="flex flex-col gap-4 text-[13px] text-gray-500 sm:flex-row sm:items-center sm:justify-between dark:text-gray-400">
                    <p>&copy; {{ date('Y') }} Relaticle</p>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <a href="{{ url('/') }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ __('Product') }}</a>
                        <a href="{{ route('pricing') }}" class="transition-colors hover:text-gray-900 dark:hover:text-white">{{ __('Pricing') }}</a>
                        <a href="{{ route('discord') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 transition-colors hover:text-gray-900 dark:hover:text-white">
                            <x-ri-discord-fill class="h-4 w-4" />
                            {{ __('Discord') }}
                        </a>
                        <a href="https://github.com/Relaticle/relaticle" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 transition-colors hover:text-gray-900 dark:hover:text-white">
                            <x-ri-github-fill class="h-4 w-4" />
                            {{ __('GitHub') }}
                        </a>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <x-documentation::search-dialog />
</div>

</body>
</html>
