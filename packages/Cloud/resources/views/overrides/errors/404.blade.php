@php
    $suggestion = app(\Relaticle\Cloud\Marketing\Support\MarketingNavigation::class)->closestTo(request()->path());
    $user = auth('web')->user();
    $home = $user || request()->is('app', 'app/*')
        ? ['label' => __('Go to your workspace'), 'url' => $user ? app(\App\Support\Auth\LoginDestination::class)->resolve($user, null) : route('login')]
        : ['label' => __('Back to home'), 'url' => url('/')];

    $links = array_filter([
        (string) config('chat.assistant_name') => route('ai'),
        __('Pricing') => route('pricing'),
        __('Help center') => Route::has('help.index') ? route('help.index') : null,
        __('Developers') => Route::has('documentation.index') ? route('documentation.index') : null,
        __('Contact us') => route('contact'),
    ], fn (?string $url): bool => $url !== null && $url !== $suggestion?->url);
@endphp

<x-cloud::guest-layout :title="__('Page not found').' - Relaticle'" robots="noindex">
    <section class="relative flex min-h-[calc(100svh-4rem)] items-center overflow-hidden bg-white py-24 dark:bg-gray-950">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-96" aria-hidden="true">
            <svg width="100%" height="100%" class="absolute inset-0 opacity-40 dark:opacity-30">
                <pattern id="dots-not-found" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="#6B7280"/></pattern>
                <rect width="100%" height="100%" fill="url(#dots-not-found)"/>
            </svg>
            <x-cloud::marketing.interactive-dot-grid-glow/>
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-white/70 to-white dark:via-gray-950/70 dark:to-gray-950"></div>
        </div>

        <div class="relative mx-auto max-w-xl px-6 text-center">
            <p class="font-mono text-sm font-medium text-primary dark:text-primary-400">404</p>

            <h1 class="mt-4 font-display text-4xl font-bold tracking-[-0.02em] text-gray-950 sm:text-5xl dark:text-white">
                {{ __('Page not found') }}
            </h1>

            <p class="mx-auto mt-5 max-w-sm text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                @if($suggestion)
                    {{ __('Nothing lives at this address. You were probably looking for :page.', ['page' => $suggestion->label]) }}
                @else
                    {{ __('The page may have moved, or the link may be wrong.') }}
                @endif
            </p>

            <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                @if($suggestion)
                    <x-cloud::marketing.button :href="$suggestion->url" icon-trailing="ri-arrow-right-line">
                        {{ __('Go to :page', ['page' => $suggestion->label]) }}
                    </x-cloud::marketing.button>
                    <x-cloud::marketing.button variant="secondary" :href="$home['url']">
                        {{ $home['label'] }}
                    </x-cloud::marketing.button>
                @else
                    <x-cloud::marketing.button :href="$home['url']" icon-trailing="ri-arrow-right-line">
                        {{ $home['label'] }}
                    </x-cloud::marketing.button>
                @endif
            </div>

            <nav aria-label="{{ __('Popular pages') }}" class="mt-14 border-t border-gray-100 pt-8 dark:border-white/[0.06]">
                <ul class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 text-sm">
                    @foreach($links as $label => $url)
                        <li>
                            <a href="{{ $url }}" class="text-gray-500 transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:text-gray-400 dark:hover:text-primary-300">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>
    </section>
</x-cloud::guest-layout>
