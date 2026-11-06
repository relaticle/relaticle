@php
    /**
     * @var array<string, mixed> $relaticle
     * @var array<string, mixed> $competitor
     * @var string $competitorSlug
     */
    $copy = [
        'hubspot' => [
            'badge' => __('Alternative'),
            'opening' => __('HubSpot\'s free CRM works for very small teams, and its paid Hubs bundle marketing, sales, and service automation well beyond core CRM. That breadth is real, and if you need an integrated marketing or service suite today, HubSpot\'s is more mature. If you want a self-hosted, open-source CRM with built-in AI and flat pricing that doesn\'t grow with every seat you add, Relaticle is the alternative.'),
            'sections' => [
                [
                    'heading' => __('License & pricing'),
                    'body' => __('HubSpot is closed-source (:hubspotPricing), and cost climbs fast once you add paid Hubs and seats. Relaticle is AGPL-3.0, with a flat :relaticlePricing on the hosted plan and no per-Hub upsells.', ['hubspotPricing' => $competitor['pricing'], 'relaticlePricing' => $relaticle['pricing']]),
                ],
                [
                    'heading' => __('AI capabilities'),
                    'body' => __('HubSpot\'s AI: :hubspotAi. Relaticle\'s AI: :relaticleAi.', ['hubspotAi' => $competitor['ai'], 'relaticleAi' => $relaticle['ai']]),
                ],
                [
                    'heading' => __('Data ownership & deployment'),
                    'body' => __('HubSpot: :hubspotSelfHost. Relaticle: :relaticleStack. :relaticleSelfHost.', ['hubspotSelfHost' => $competitor['self_host'], 'relaticleStack' => $relaticle['stack'], 'relaticleSelfHost' => $relaticle['self_host']]),
                ],
                [
                    'heading' => __('Extensibility'),
                    'body' => __('HubSpot\'s extensibility: :hubspotExtensibility. Relaticle\'s extensibility: :relaticleExtensibility.', ['hubspotExtensibility' => $competitor['extensibility'], 'relaticleExtensibility' => $relaticle['extensibility']]),
                ],
            ],
        ],
    ][$competitorSlug];

    $titles = [
        'hubspot' => __('HubSpot Alternative'),
    ];

    /*
     * The <title> tag, deliberately not the same string as the H1. "Open source"
     * leads because that is the language of the queries these pages already rank
     * for, and the phrasing mirrors them directly rather than restating the H1.
     * Kept under ~60 characters so Google does not truncate it.
     */
    $metaTitles = [
        'hubspot' => __('Open Source HubSpot Alternative, Self-Hosted | Relaticle'),
    ];

    /*
     * Structural facts only, no prices or counts: those live in
     * competitor-facts.php so `gtm:stale-facts` can age them out.
     */
    $descriptions = [
        'hubspot' => __('HubSpot is closed-source with no self-hosting option. Relaticle is AGPL-3.0, self-hostable free, flat-priced for unlimited users, with built-in AI and MCP.'),
    ];

    $title = $titles[$competitorSlug];
    $metaTitle = $metaTitles[$competitorSlug];
    $description = $descriptions[$competitorSlug];
    $factsVerifiedAt = \Carbon\CarbonImmutable::parse($relaticle['verified'])->format('F j, Y');
    $assistantName = (string) config('chat.assistant_name');
    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';

    $primarySources = collect([$relaticle, $competitor])
        ->flatMap(fn (array $facts): array => array_filter([
            ['label' => __(':name pricing', ['name' => $facts['name']]), 'url' => $facts['source_urls']['pricing']],
            isset($facts['source_urls']['repository'])
                ? ['label' => __(':name on GitHub', ['name' => $facts['name']]), 'url' => $facts['source_urls']['repository']]
                : null,
        ]));
@endphp

<x-cloud::guest-layout
    :title="$metaTitle"
    :description="$description"
    :ogTitle="$metaTitle"
>
    <x-cloud::marketing.hero align="start">
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ $copy['badge'] }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>{{ $title }}</x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ $copy['opening'] }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="{{ route('selfHosted') }}">
                    {{ __('Self-host it') }}
                </x-cloud::marketing.button>
            </div>
        </x-slot:aside>
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame id="glance">
        <x-cloud::marketing.frame-header :label="__('At a glance')" :lead="__(':title at a glance', ['title' => $title])">
            {{ __('Eight facts, each dated in the facts file.') }}
        </x-cloud::marketing.frame-header>

        @include('cloud::marketing.partials.comparison-facts-table')
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="details">
        <div class="bg-rail grid gap-px">
            @foreach ($copy['sections'] as $section)
                <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
                    <h2 class="bg-surface px-6 py-8 text-lg font-medium text-gray-950 md:px-10 lg:col-span-4 dark:text-white">{{ $section['heading'] }}</h2>
                    <div class="bg-surface px-6 py-8 md:px-10 lg:col-span-8">
                        <p class="type-prose text-gray-700 dark:text-gray-300">{{ $section['body'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="bg-surface border-rail border-t px-6 py-5 text-sm text-gray-500 md:px-8 dark:text-gray-400">
            <p>{{ __('Facts verified :date. Sources are dated in the underlying facts file. See :repo.', ['date' => $factsVerifiedAt, 'repo' => 'github.com/relaticle/relaticle']) }}</p>
            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <span>{{ __('Primary sources:') }}</span>
                @foreach ($primarySources as $source)
                    <a href="{{ $source['url'] }}" rel="nofollow noopener" target="_blank" class="{{ $linkClass }}">{{ $source['label'] }}</a>
                @endforeach
            </p>
            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <a href="{{ route('selfHosted') }}" class="{{ $linkClass }}">{{ __('See self-hosting options') }}</a>
                <a href="{{ route('ai') }}" class="{{ $linkClass }}">{{ __('Meet :name, the built-in AI assistant', ['name' => $assistantName]) }}</a>
            </p>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Try Relaticle yourself.')" :continuation="__('Self-host it free under AGPL-3.0, or start on the hosted plan.')">
        <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
            {{ __('Start for free') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ route('contact') }}">
            {{ __('Get in touch') }}
        </x-cloud::marketing.button>
    </x-cloud::marketing.closing-band>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($page) => $page
                ->name($title)
                ->description($description)
                ->url(url()->current()))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name($title)->item(url()->current()),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
