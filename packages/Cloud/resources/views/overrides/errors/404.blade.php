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
    <x-cloud::marketing.hero align="start">
        <x-slot:eyebrow>
            <svg viewBox="-35 50 1640 1060" class="not-found-mark mb-10 block h-32 w-auto md:h-44" fill="none" aria-hidden="true">
                <path d="M579 570.7 L157.9 254.9" stroke="#5D54E8" stroke-width="70" stroke-linecap="round"/>
                <path d="M579 570.7 L965 886.5" stroke="#5D54E8" stroke-width="70" stroke-linecap="round"/>
                <path d="M579 570.7 L868.6 392.5" stroke="#5D54E8" stroke-width="70" stroke-linecap="round"/>

                <g class="not-found-mark-trace">
                    <circle cx="1035.2" cy="290" r="122.8" class="not-found-mark-slot stroke-gray-300 dark:stroke-gray-600" stroke-width="10" stroke-dasharray="26 30"/>
                    <circle cx="934" cy="364" r="27" fill="#5D54E8" opacity="0.5"/>
                    <circle cx="978" cy="322" r="18" fill="#5D54E8" opacity="0.35"/>
                    <path d="M1168 258 Q 1232 206 1296 222" class="stroke-gray-300 dark:stroke-gray-600" stroke-width="14" stroke-linecap="round" stroke-dasharray="0.1 40"/>
                </g>

                <circle cx="157.9" cy="254.9" r="157.9" fill="#5D54E8" opacity="0.85"/>
                <circle cx="965" cy="886.5" r="175.5" fill="#5D54E8" opacity="0.9"/>
                <circle cx="579" cy="570.7" r="298.3" fill="#5D54E8"/>

                <g class="not-found-mark-node">
                    <g class="not-found-mark-reach">
                        <g transform="rotate(-22 1435.2 220)">
                            <path d="M1435.2 220 L1279.5 315.8" stroke="#5D54E8" stroke-width="70" stroke-linecap="round" opacity="0.75"/>
                            <circle cx="1435.2" cy="220" r="122.8" fill="#5D54E8" opacity="0.75"/>
                        </g>
                    </g>
                </g>
            </svg>
            <x-cloud::marketing.eyebrow>{{ __('Error 404') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>{{ __('Page not found') }}</x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                @if($suggestion)
                    {{ __('Nothing lives at this address. You were probably looking for :page.', ['page' => $suggestion->label]) }}
                @else
                    {{ __('The page may have moved, or the link may be wrong.') }}
                @endif
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
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
        </x-slot:aside>

        <nav aria-label="{{ __('Popular pages') }}">
            <ul @class([
                'bg-rail border-rail grid grid-cols-1 gap-px border-t sm:grid-cols-2',
                'lg:grid-cols-3' => count($links) === 3,
                'lg:grid-cols-4' => count($links) === 4,
                'lg:grid-cols-5' => count($links) >= 5,
            ])>
                @foreach($links as $label => $url)
                    <li class="bg-surface">
                        <a href="{{ $url }}" class="hover:bg-tint group flex h-full items-center justify-between gap-3 p-6 text-base font-medium text-gray-950 transition-colors md:p-8 dark:text-white">
                            {{ $label }}
                            <x-ri-arrow-right-line class="size-4 shrink-0 text-gray-400 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true"/>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </x-cloud::marketing.hero>
</x-cloud::guest-layout>
