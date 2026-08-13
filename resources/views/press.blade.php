@php
    $facts = \App\Support\CompetitorFacts::all()['relaticle'];

    // Derived from the facts file's `ai` field ("32 first-party MCP tools plus…")
    // rather than a fresh literal, so this number can never drift from the one
    // source of truth for public competitor/company claims.
    preg_match('/^(\d+)/', $facts['ai'], $mcpToolMatch);
    $mcpToolCount = (int) ($mcpToolMatch[1] ?? 0);

    $starsAsOf = \Carbon\CarbonImmutable::parse($facts['stars_verified'])->format('F j, Y');
    $factsVerifiedAt = \Carbon\CarbonImmutable::parse($facts['verified'])->format('F j, Y');
@endphp

<x-guest-layout
    :title="__('Press Kit & Facts') . ' - Relaticle'"
    :description="__('Relaticle press kit: founding date, license, GitHub stars, pricing, tech stack, and product screenshots for journalists covering open-source CRM.')"
    :ogTitle="__('Press Kit & Facts') . ' - Relaticle'"
    :ogDescription="__('Everything a listicle author or journalist needs to cover Relaticle in ten minutes: dated company facts, product screenshots, and logo downloads.')"
>
    <section class="relative pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-5xl mx-auto px-6 lg:px-8">

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-article-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Press kit') }}</span>
                </div>
            </div>

            {{-- Header --}}
            <div class="text-center max-w-2xl mx-auto mb-16 md:mb-20">
                <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                    {{ __('Press Kit & Facts') }}
                </h1>
                <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('Relaticle is the self-host-first, agent-native open-source CRM — AI that acts with your approval, works out of the box, one flat price for the whole team.') }}
                </p>
            </div>

            {{-- Company facts --}}
            <div class="max-w-3xl mx-auto mb-16 md:mb-20">
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white text-center mb-8">
                    {{ __('Company facts') }}
                </h2>

                {{--
                    A <table> here would collapse to a run-on line for the markdown-response
                    channel (GPTBot/ClaudeBot/PerplexityBot) — see the same note in
                    pricing.blade.php. <ul>/<li>/<p> survive conversion cleanly instead.
                --}}
                <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-200/80 bg-white dark:divide-white/[0.04] dark:border-white/[0.06] dark:bg-white/[0.02]">
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Founded') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">2024</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('License') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ $facts['license'] }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Tech stack') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ $facts['stack'] }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('GitHub stars') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ __(':stars stars (as of :date)', ['stars' => number_format($facts['stars']), 'date' => $starsAsOf]) }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Pricing') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ $facts['pricing'] }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('AI & MCP') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">{{ __(':count MCP tools plus a built-in AI chat assistant — both work fully self-hosted.', ['count' => $mcpToolCount]) }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Links') }}</p>
                        <p class="mt-1.5 flex flex-wrap items-center gap-4 text-sm">
                            <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                                <x-ri-github-fill class="h-4 w-4"/>
                                github.com/relaticle/relaticle
                            </a>
                            <a href="{{ route('discord') }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                                <x-ri-discord-fill class="h-4 w-4"/>
                                {{ __('Discord') }}
                            </a>
                            <a href="https://x.com/relaticle" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                                <x-ri-twitter-x-fill class="h-4 w-4"/>
                                x.com/relaticle
                            </a>
                        </p>
                    </li>
                </ul>
                <p class="mt-4 text-xs text-gray-400 dark:text-gray-500 text-center">
                    {{ __('Facts verified :date. See the full dated source at :repo.', ['date' => $factsVerifiedAt, 'repo' => 'github.com/relaticle/relaticle']) }}
                </p>
            </div>

            {{-- Product screenshots --}}
            <div class="max-w-5xl mx-auto mb-16 md:mb-20">
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white text-center mb-8">
                    {{ __('Product screenshots') }}
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <figure class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] overflow-hidden bg-white dark:bg-neutral-950">
                        <picture>
                            <img src="{{ asset('images/app-pipeline-preview.png') }}"
                                 alt="{{ __('Relaticle opportunities board with deals grouped into pipeline stages, showing deal value and close date') }}"
                                 class="block dark:hidden w-full h-auto"
                                 width="1440" height="900" loading="lazy">
                            <img src="{{ asset('images/app-pipeline-preview-dark.png') }}"
                                 alt="{{ __('Relaticle opportunities board with deals grouped into pipeline stages, showing deal value and close date') }}"
                                 class="hidden dark:block w-full h-auto"
                                 width="1440" height="900" loading="lazy">
                        </picture>
                        <figcaption class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Pipeline board') }}</figcaption>
                    </figure>
                    <figure class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] overflow-hidden bg-white dark:bg-neutral-950">
                        <picture>
                            <img src="{{ asset('images/app-companies-preview.png') }}"
                                 alt="{{ __('Relaticle companies list showing account owner, ICP status, and website domain for each company') }}"
                                 class="block dark:hidden w-full h-auto"
                                 width="1440" height="900" loading="lazy">
                            <img src="{{ asset('images/app-companies-preview-dark.png') }}"
                                 alt="{{ __('Relaticle companies list showing account owner, ICP status, and website domain for each company') }}"
                                 class="hidden dark:block w-full h-auto"
                                 width="1440" height="900" loading="lazy">
                        </picture>
                        <figcaption class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Companies list') }}</figcaption>
                    </figure>
                    <figure class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] overflow-hidden bg-white dark:bg-neutral-950">
                        <picture>
                            <img src="{{ asset('images/app-custom-fields-preview.png') }}"
                                 alt="{{ __('Relaticle custom fields settings showing field name, type, constraints, and properties for Opportunities') }}"
                                 class="block dark:hidden w-full h-auto"
                                 width="1440" height="900" loading="lazy">
                            <img src="{{ asset('images/app-custom-fields-preview-dark.png') }}"
                                 alt="{{ __('Relaticle custom fields settings showing field name, type, constraints, and properties for Opportunities') }}"
                                 class="hidden dark:block w-full h-auto"
                                 width="1440" height="900" loading="lazy">
                        </picture>
                        <figcaption class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Custom fields') }}</figcaption>
                    </figure>
                </div>
            </div>

            {{-- Logo & brand assets --}}
            <div class="max-w-3xl mx-auto mb-16 md:mb-20">
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white text-center mb-8">
                    {{ __('Logo & brand assets') }}
                </h2>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-4 rounded-2xl border border-gray-200/80 bg-white dark:border-white/[0.06] dark:bg-white/[0.02] px-6 py-8">
                    <x-brand.logomark class="h-12 w-12 text-primary dark:text-primary-400"/>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <a href="{{ asset('brand/logomark.svg') }}" download class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-download-line class="h-4 w-4"/>
                            {{ __('Logomark (SVG)') }}
                        </a>
                        <a href="{{ asset('brand/logo-white.png') }}" download class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors">
                            <x-ri-download-line class="h-4 w-4"/>
                            {{ __('Logo, white (PNG)') }}
                        </a>
                    </div>
                </div>
            </div>

            {{-- Press contact --}}
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white mb-4">
                    {{ __('Press contact') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-6">
                    {{ __('For interviews, quotes, or anything else you need to cover Relaticle, reach the team through the contact form.') }}
                </p>
                <x-marketing.button variant="secondary" href="{{ route('contact') }}">
                    {{ __('Contact us') }}
                </x-marketing.button>
            </div>

        </div>
    </section>
</x-guest-layout>
