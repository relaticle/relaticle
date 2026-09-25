@php
    $facts = \App\Support\CompetitorFacts::all()['relaticle'];
    $mcpToolCount = \App\Support\CompetitorFacts::mcpToolCount();
    $starsLabel = $githubStars > 0
        ? __(':stars stars', ['stars' => number_format($githubStars)])
        : __(':stars stars as of :date', ['stars' => number_format($facts['stars']), 'date' => \Illuminate\Support\Facades\Date::parse($facts['stars_verified'])->format('F j, Y')]);
    $factsVerifiedAt = \Illuminate\Support\Facades\Date::parse($facts['verified'])->format('F j, Y');
    $brand = json_decode(file_get_contents(public_path('brand/kit/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $avatarLabels = ['purple' => __('Purple'), 'light' => __('Light'), 'dark' => __('Dark')];
    $screenshots = [
        ['id' => 'pipeline', 'title' => __('Sales pipeline'), 'alt' => __('Relaticle opportunities board with deals grouped by pipeline stage')],
        ['id' => 'companies', 'title' => __('Companies'), 'alt' => __('Relaticle companies list with account owners, ICP status, and website domains')],
        ['id' => 'custom-fields', 'title' => __('Custom fields'), 'alt' => __('Relaticle custom field settings for opportunities')],
    ];
    $downloadClass = 'inline-flex min-h-11 items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700 transition hover:border-primary-300 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:border-primary-400 dark:hover:text-primary-300';
@endphp

<x-guest-layout
    :title="__('Press kit & brand assets') . ' - Relaticle'"
    :description="__('Download official Relaticle logos, social media avatars, and product screenshots. Find company facts and press contact details.')"
>
    <div class="bg-white text-gray-950 dark:bg-gray-950 dark:text-white">
        <div class="mx-auto max-w-6xl px-6 pb-24 pt-32 sm:pt-40 lg:px-8">
            <div class="max-w-3xl">
                <p class="mb-5 text-xs font-semibold uppercase tracking-widest text-primary dark:text-primary-400">{{ __('Press & brand resources') }}</p>
                <h1 class="font-display text-4xl font-bold leading-tight tracking-tight sm:text-6xl">{{ __('Relaticle, ready to share.') }}</h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-gray-600 dark:text-gray-400">
                    {{ __('Official logos, social avatars, product screenshots, and the facts behind Relaticle. Everything you need to tell the story.') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a href="{{ asset('brand/kit.zip') }}" download="relaticle-brand-kit.zip" class="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-primary px-6 text-sm font-semibold text-white transition hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:bg-primary-500 dark:hover:bg-primary-600">
                        <x-ri-download-2-line class="size-4" aria-hidden="true"/>
                        {{ __('Download brand kit') }}
                        <span class="font-normal text-white/80">{{ __('ZIP') }}</span>
                    </a>
                    <a href="#facts" class="inline-flex min-h-12 items-center gap-2 rounded-xl px-4 text-sm font-medium text-gray-600 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:text-gray-300 dark:hover:text-primary-300">
                        {{ __('About Relaticle') }}
                        <x-ri-arrow-down-line class="size-4" aria-hidden="true"/>
                    </a>
                </div>
                <p class="mt-4 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('Includes SVG logos, transparent PNGs, :count platform presets, and YouTube watermarks.', ['count' => count($brand['platforms'])]) }}</p>
            </div>

            <nav aria-label="{{ __('Press kit sections') }}" class="mt-12 flex flex-wrap gap-x-7 gap-y-3 border-b border-gray-200 pb-5 text-sm font-medium dark:border-gray-800">
                @foreach (['logos' => __('Logos'), 'social' => __('Social avatars'), 'screenshots' => __('Screenshots'), 'facts' => __('Company facts'), 'contact' => __('Press contact')] as $anchor => $label)
                    <a href="#{{ $anchor }}" class="rounded-sm py-2 text-gray-600 transition hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary dark:text-gray-400 dark:hover:text-primary-300">{{ $label }}</a>
                @endforeach
            </nav>

            <section id="logos" aria-labelledby="logos-title" class="scroll-mt-24 pt-14 sm:pt-16">
                <h2 id="logos-title" class="font-display text-2xl font-bold tracking-tight sm:text-3xl">{{ __('The logo') }}</h2>
                <p class="mt-3 max-w-2xl text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Use the full logo when space allows. Choose the symbol for compact placements. All files have transparent backgrounds.') }}</p>
                <div class="mt-7 grid gap-5 sm:grid-cols-2">
                    @foreach (['color' => __('For light backgrounds'), 'white' => __('For dark backgrounds')] as $variant => $label)
                        <article class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800">
                            <div @class(['flex h-52 items-center justify-center px-8 sm:h-60', 'bg-white' => $variant === 'color', 'bg-gray-950' => $variant === 'white'])>
                                <img src="{{ asset('brand/kit/logos/lockup-'.$variant.'.svg') }}" alt="{{ __('Relaticle :variant logo', ['variant' => $variant === 'color' ? __('color') : __('white')]) }}" width="252" height="72" class="w-full max-w-72"/>
                            </div>
                            <div class="border-t border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-gray-900/50">
                                <h3 class="text-sm font-semibold">{{ $label }}</h3>
                                <div class="mt-4 space-y-3">
                                    @foreach (collect($brand['logos'])->where('variant', $variant) as $logo)
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <span class="text-sm text-gray-600 dark:text-gray-400">{{ $logo['kind'] === 'lockup' ? __('Full logo') : __('Symbol') }}</span>
                                            <div class="flex gap-2">
                                                @foreach (['svg' => __('SVG'), 'png' => __('PNG')] as $format => $formatLabel)
                                                    <a href="{{ asset('brand/kit/'.$logo[$format]) }}" download class="{{ $downloadClass }}" aria-label="{{ __('Download :variant :kind as :format', ['variant' => $variant === 'color' ? __('color') : __('white'), 'kind' => $logo['kind'] === 'lockup' ? __('full logo') : __('symbol'), 'format' => $formatLabel]) }}">{{ $formatLabel }}<x-ri-download-2-line class="size-3.5" aria-hidden="true"/></a>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-6 grid gap-5 rounded-2xl bg-gray-50 p-6 text-sm dark:bg-gray-900/50 sm:grid-cols-3">
                    <div><h3 class="font-semibold">{{ __('Give it room') }}</h3><p class="mt-2 leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Keep the supplied padding. Leave space between the logo and surrounding text or graphics.') }}</p></div>
                    <div><h3 class="font-semibold">{{ __('Keep it recognizable') }}</h3><p class="mt-2 leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Preserve the proportions and colors. Avoid stretching, rotating, or adding effects.') }}</p></div>
                    <div><h3 class="font-semibold">{{ __('Choose the right format') }}</h3><p class="mt-2 leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Use SVG for layouts that need to scale. Use PNG for uploads, slides, and video overlays.') }}</p></div>
                </div>
            </section>

            <section id="social" aria-labelledby="social-title" class="scroll-mt-24 pt-16 sm:pt-20">
                <h2 id="social-title" class="font-display text-2xl font-bold tracking-tight sm:text-3xl">{{ __('One identity. Every channel.') }}</h2>
                <p class="mt-3 max-w-2xl text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Purple is the primary avatar. Upload the full square image and let the platform apply its crop. Keep one color across accounts.') }}</p>
                <div class="mt-7 grid gap-5 sm:grid-cols-3">
                    @foreach ($brand['avatars'] as $avatar)
                        <article class="rounded-2xl border border-gray-200 p-5 dark:border-gray-800">
                            <div class="flex min-h-7 items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold">{{ $avatarLabels[$avatar['id']] }}</h3>
                                @if ($avatar['id'] === 'purple')
                                    <span class="rounded-full bg-primary-50 px-2.5 py-1 text-xs font-medium text-primary-700 dark:bg-primary-950 dark:text-primary-300">{{ __('Primary') }}</span>
                                @endif
                            </div>
                            <div class="flex justify-center py-8">
                                <img src="{{ asset('brand/kit/'.$avatar['svg']) }}" alt="{{ __('Relaticle :color social avatar', ['color' => $avatarLabels[$avatar['id']]]) }}" width="1024" height="1024" class="size-36 rounded-full ring-1 ring-gray-200 dark:ring-gray-700" loading="lazy"/>
                            </div>
                            <div class="flex items-center justify-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-800" aria-hidden="true">
                                <img src="{{ asset('brand/kit/'.$avatar['svg']) }}" alt="" width="32" height="32" class="size-8 ring-1 ring-gray-200 dark:ring-gray-700" loading="lazy"/>
                                <img src="{{ asset('brand/kit/'.$avatar['svg']) }}" alt="" width="32" height="32" class="size-8 rounded-lg ring-1 ring-gray-200 dark:ring-gray-700" loading="lazy"/>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Square or rounded') }}</span>
                            </div>
                            <a href="{{ asset('brand/kit/'.$avatar['png']) }}" download class="{{ $downloadClass }} mt-5 w-full justify-center" aria-label="{{ __('Download :color avatar PNG, 1024 pixels', ['color' => $avatarLabels[$avatar['id']]]) }}"><x-ri-download-2-line class="size-4" aria-hidden="true"/>{{ __('PNG · 1024 px') }}</a>
                            <div class="mt-2 flex justify-center gap-5 text-xs">
                                <a href="{{ asset('brand/kit/'.$avatar['largePng']) }}" download class="inline-flex min-h-11 items-center rounded-sm text-gray-600 underline decoration-gray-300 underline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary dark:text-gray-400" aria-label="{{ __('Download :color avatar PNG, 2048 pixels', ['color' => $avatarLabels[$avatar['id']]]) }}">{{ __('PNG · 2048 px') }}</a>
                                <a href="{{ asset('brand/kit/'.$avatar['svg']) }}" download class="inline-flex min-h-11 items-center rounded-sm text-gray-600 underline decoration-gray-300 underline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary dark:text-gray-400" aria-label="{{ __('Download :color avatar SVG', ['color' => $avatarLabels[$avatar['id']]]) }}">{{ __('SVG') }}</a>
                            </div>
                        </article>
                    @endforeach
                </div>
                <details class="group mt-5 rounded-2xl border border-gray-200 dark:border-gray-800" x-data="{ color: 'purple' }">
                    <summary class="flex min-h-16 cursor-pointer list-none items-center justify-between gap-4 rounded-2xl px-6 py-4 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary [&::-webkit-details-marker]:hidden">
                        <span class="text-sm font-semibold">{{ __('Choose a platform') }}<span class="ml-2 font-normal text-gray-500 dark:text-gray-400">{{ __(':count presets', ['count' => count($brand['platforms'])]) }}</span></span>
                        <x-ri-add-line class="size-5 shrink-0 transition group-open:rotate-45" aria-hidden="true"/>
                    </summary>
                    <div class="border-t border-gray-200 p-6 dark:border-gray-800">
                        <div class="flex flex-wrap items-end justify-between gap-5">
                            <p class="max-w-lg text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Convenient square PNG exports for each channel. Platform requirements can change, so preview the final crop before saving.') }}</p>
                            <div>
                                <label for="avatar-color" class="mb-2 block text-xs font-medium">{{ __('Avatar color') }}</label>
                                <select id="avatar-color" x-model="color" class="min-h-11 rounded-lg border-gray-300 bg-white text-sm text-gray-800 focus:border-primary focus:ring-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                    @foreach ($avatarLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($brand['platforms'] as $platform)
                                <a href="{{ asset('brand/kit/'.$platform['files']['purple']) }}" :href="{{ \Illuminate\Support\Js::from(collect($platform['files'])->map(fn (string $file): string => asset('brand/kit/'.$file))) }}[color]" download class="{{ $downloadClass }} justify-between py-3 text-left" data-platform="{{ $platform['id'] }}">
                                    <span>{{ $platform['name'] }}<span class="mt-0.5 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $platform['size'] }} × {{ $platform['size'] }}</span></span>
                                    <x-ri-download-2-line class="size-4 shrink-0" aria-hidden="true"/>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </details>
                <div class="mt-5 flex flex-wrap items-center justify-between gap-5 rounded-2xl bg-gray-50 p-6 dark:bg-gray-900/50">
                    <div><h3 class="text-sm font-semibold">{{ __('YouTube video watermark') }}</h3><p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Small PNGs for the corner of your videos. Choose white for dark footage or purple for a solid background.') }}</p></div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($brand['watermarks'] as $watermark)
                            <a href="{{ asset('brand/kit/'.$watermark['png']) }}" download class="{{ $downloadClass }}" aria-label="{{ __('Download :color YouTube watermark PNG', ['color' => $watermark['id'] === 'white' ? __('white') : __('purple')]) }}">{{ $watermark['id'] === 'white' ? __('White PNG') : __('Purple PNG') }}<x-ri-download-2-line class="size-4" aria-hidden="true"/></a>
                        @endforeach
                    </div>
                </div>
            </section>

            <section id="screenshots" aria-labelledby="screenshots-title" class="scroll-mt-24 pt-16 sm:pt-20">
                <h2 id="screenshots-title" class="font-display text-2xl font-bold tracking-tight sm:text-3xl">{{ __('Inside Relaticle') }}</h2>
                <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Product screenshots in light and dark themes. Download the original PNGs for articles, presentations, and reviews.') }}</p>
                <div class="mt-7 grid gap-5 sm:grid-cols-3">
                    @foreach ($screenshots as $screenshot)
                        <figure class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800">
                            <img src="{{ asset('images/app-'.$screenshot['id'].'-preview.png') }}" alt="{{ $screenshot['alt'] }}" width="2880" height="2232" class="block h-auto w-full dark:hidden" loading="lazy"/>
                            <img src="{{ asset('images/app-'.$screenshot['id'].'-preview-dark.png') }}" alt="{{ $screenshot['alt'] }}" width="2880" height="2232" class="hidden h-auto w-full dark:block" loading="lazy"/>
                            <figcaption class="border-t border-gray-200 p-5 dark:border-gray-800">
                                <h3 class="text-sm font-semibold">{{ $screenshot['title'] }}</h3>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach (['' => __('Light PNG'), '-dark' => __('Dark PNG')] as $suffix => $label)
                                        <a href="{{ asset('images/app-'.$screenshot['id'].'-preview'.$suffix.'.png') }}" download class="{{ $downloadClass }}" aria-label="{{ __('Download :screenshot, :theme', ['screenshot' => $screenshot['title'], 'theme' => $label]) }}">{{ $label }}</a>
                                    @endforeach
                                </div>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            </section>

            <section id="facts" aria-labelledby="facts-title" class="scroll-mt-24 pt-16 sm:pt-20">
                <div class="grid gap-8 lg:grid-cols-3 lg:gap-12">
                    <div>
                        <h2 id="facts-title" class="font-display text-2xl font-bold tracking-tight sm:text-3xl">{{ __('About Relaticle') }}</h2>
                        <p class="mt-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Relaticle is an open-source CRM for managing contacts, companies, deals, and tasks. Its built-in AI assistant proposes changes for you to review and approve.') }}</p>
                        <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Choose Relaticle Cloud or run it on your own server.') }}</p>
                        <div class="mt-5 flex flex-wrap gap-x-5 gap-y-2 text-sm font-medium">
                            <a href="{{ $facts['source_urls']['repository'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-sm text-primary focus-visible:outline-2 focus-visible:outline-primary dark:text-primary-400"><x-ri-github-fill class="size-4" aria-hidden="true"/>{{ __('GitHub') }}</a>
                            <a href="{{ route('discord') }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-sm text-primary focus-visible:outline-2 focus-visible:outline-primary dark:text-primary-400"><x-ri-discord-fill class="size-4" aria-hidden="true"/>{{ __('Discord') }}</a>
                            <a href="https://x.com/relaticle" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center gap-2 rounded-sm text-primary focus-visible:outline-2 focus-visible:outline-primary dark:text-primary-400"><x-ri-twitter-x-fill class="size-4" aria-hidden="true"/>{{ __('X') }}</a>
                        </div>
                    </div>
                    <div class="lg:col-span-2">
                        <ul class="grid gap-px overflow-hidden rounded-2xl border border-gray-200 bg-gray-200 dark:border-gray-800 dark:bg-gray-800 sm:grid-cols-2">
                            @foreach ([__('Founded') => '2024', __('License') => $facts['license'], __('Tech stack') => $facts['stack'], __('GitHub stars') => $starsLabel, __('Pricing') => $facts['pricing'], __('AI & MCP') => __(':count MCP tools and a built-in AI chat assistant. Both support self-hosting.', ['count' => $mcpToolCount])] as $label => $value)
                                <li class="bg-white p-5 dark:bg-gray-950"><p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="mt-2 text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $value }}</p></li>
                            @endforeach
                        </ul>
                        <p class="mt-4 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('Company facts verified :date.', ['date' => $factsVerifiedAt]) }} <a href="{{ route('pricing') }}" class="underline underline-offset-4">{{ __('See current pricing') }}</a>.</p>
                    </div>
                </div>
            </section>

            <section id="contact" aria-labelledby="contact-title" class="mt-16 scroll-mt-24 rounded-2xl border border-gray-200 bg-gray-50 p-7 dark:border-gray-800 dark:bg-gray-900/50 sm:mt-20 sm:p-10">
                <div class="flex flex-wrap items-center justify-between gap-6">
                    <div class="max-w-xl"><h2 id="contact-title" class="font-display text-2xl font-bold tracking-tight">{{ __('Working on a story?') }}</h2><p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Get in touch for interviews, product questions, or help finding the right assets.') }}</p></div>
                    <a href="{{ route('contact') }}" class="{{ $downloadClass }}">{{ __('Contact the team') }}<x-ri-arrow-right-line class="size-4" aria-hidden="true"/></a>
                </div>
                <p class="mt-6 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('Use these assets to identify or cover Relaticle. Do not imply a partnership or endorsement without our agreement.') }}</p>
            </section>
        </div>
    </div>
</x-guest-layout>
