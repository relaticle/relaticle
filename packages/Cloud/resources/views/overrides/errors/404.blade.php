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
    <x-cloud::marketing.frame>
        <div class="border-rail border-b px-6 py-5 md:px-10">
            <x-cloud::marketing.eyebrow>{{ __('Error 404') }}</x-cloud::marketing.eyebrow>
        </div>

        <div class="px-6 py-20 md:px-10 md:py-32">
            <h1 class="type-display max-w-4xl text-gray-950 dark:text-white">{{ __('Page not found') }}</h1>

            <p class="mt-5 max-w-2xl text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                @if($suggestion)
                    {{ __('Nothing lives at this address. You were probably looking for :page.', ['page' => $suggestion->label]) }}
                @else
                    {{ __('The page may have moved, or the link may be wrong.') }}
                @endif
            </p>

            <div class="mt-8 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                @if($suggestion)
                    <x-cloud::marketing.button :href="$suggestion->url" icon-trailing="lucide-arrow-right">
                        {{ __('Go to :page', ['page' => $suggestion->label]) }}
                    </x-cloud::marketing.button>
                    <x-cloud::marketing.button variant="secondary" :href="$home['url']">
                        {{ $home['label'] }}
                    </x-cloud::marketing.button>
                @else
                    <x-cloud::marketing.button :href="$home['url']" icon-trailing="lucide-arrow-right">
                        {{ $home['label'] }}
                    </x-cloud::marketing.button>
                @endif
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <nav aria-label="{{ __('Popular pages') }}">
            <ul @class([
                'bg-rail grid grid-cols-1 gap-px sm:grid-cols-2',
                'lg:grid-cols-3' => count($links) === 3,
                'lg:grid-cols-4' => count($links) === 4,
                'lg:grid-cols-5' => count($links) >= 5,
            ])>
                @foreach($links as $label => $url)
                    <li class="bg-surface">
                        <a href="{{ $url }}" class="hover:bg-tint group flex h-full items-center justify-between gap-3 p-6 text-base font-medium text-gray-950 transition-colors md:p-8 dark:text-white">
                            {{ $label }}
                            <x-lucide-arrow-right class="size-4 shrink-0 text-gray-400 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true"/>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </x-cloud::marketing.frame>
</x-cloud::guest-layout>
