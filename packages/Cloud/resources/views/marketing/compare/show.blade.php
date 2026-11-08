@php
    /**
     * @var array<string, mixed> $relaticle
     * @var array<string, mixed> $competitor
     * @var string $competitorSlug
     */
    $copy = [
        'twenty' => [
            'badge' => __('Comparison'),
            'opening' => __('Relaticle and Twenty CRM are both AGPL-3.0, self-hostable CRMs with AI and a first-party MCP server. The choice comes down to what you\'re optimizing for. Pick Relaticle if you want flat pricing that doesn\'t grow with headcount, or one server to run. Pick Twenty if you want the larger open-source community or prefer a Node/NestJS stack over Laravel and PHP.'),
            'sections' => [
                [
                    'heading' => __('How do Relaticle and Twenty pricing compare?'),
                    'body' => __('Relaticle charges :relaticlePricing. The bill is the same whether two people use the workspace or two hundred. Twenty prices per user (:twentyPricing), so its bill scales with every seat you add, and the gap between the two models widens as your team grows. On licensing, both projects publish under AGPL-3.0; Twenty adds a Twenty Application Exception that license-tags its enterprise files in-repo, which means some functionality stays legally reserved even in a self-hosted deploy. Relaticle reserves no product feature. Everything a self-hosted install runs is AGPL-3.0. Only the code that runs relaticle.com\'s billing and website is licensed separately.', ['relaticlePricing' => $relaticle['pricing'], 'twentyPricing' => $competitor['pricing']]),
                ],
                [
                    'heading' => __('How do their AI and MCP setups compare?'),
                    'body' => __('Relaticle ships :relaticleAi. Every write the built-in assistant proposes renders as an approval card showing old value to new value, per field. It executes only when a human accepts it. Twenty\'s offering is :twentyAi. Both run their AI and MCP server on a self-hosted install, so try the write flow you need on each before you choose.', ['relaticleAi' => $relaticle['ai'], 'twentyAi' => $competitor['ai']]),
                ],
                [
                    'heading' => __('What does self-hosting each one take?'),
                    'body' => __('Relaticle runs on :relaticleStack, which is one process to operate, and :relaticleSelfHost. Twenty runs on :twentyStack, which means more moving parts to provision, monitor, and upgrade yourself. Twenty\'s position is :twentySelfHost. If you want the smallest possible operational footprint, a single Laravel server is hard to beat; if you already operate Node services with Redis and Postgres, Twenty\'s stack will feel familiar.', ['relaticleStack' => $relaticle['stack'], 'relaticleSelfHost' => lcfirst($relaticle['self_host']), 'twentyStack' => $competitor['stack'], 'twentySelfHost' => lcfirst($competitor['self_host'])]),
                ],
                [
                    'heading' => __('Who has the bigger community and ecosystem?'),
                    'body' => __('Twenty CRM has the bigger open-source footprint: :twentyStars GitHub stars and :twentyContributors contributors on the twentyhq/twenty repository, against Relaticle\'s :relaticleStars stars and :relaticleContributors. Twenty also ships :twentyExtensibility. Relaticle\'s extensibility is :relaticleExtensibility. If community size, third-party extensions, or the confidence of a large existing user base decides it for you, that point goes to Twenty; if you want a smaller codebase you can read end to end and extend directly, that favors Relaticle.', [
                        'twentyStars' => number_format($competitor['stars']),
                        'twentyContributors' => $competitor['contributors'],
                        'relaticleStars' => number_format($relaticle['stars']),
                        'relaticleContributors' => $relaticle['contributors'].' contributors',
                        'twentyExtensibility' => $competitor['extensibility'],
                        'relaticleExtensibility' => $relaticle['extensibility'],
                    ]),
                ],
            ],
        ],
        'espocrm' => [
            'badge' => __('Comparison'),
            'opening' => __('Relaticle and EspoCRM are both open-source, AGPL-3.0, self-hosted-first CRMs built to run on a single PHP server. Pick Relaticle if you want a built-in AI assistant with every Cloud plan and every self-hosted install. EspoCRM sells its AI as a paid add-on and offers MCP as a free extension. Pick EspoCRM if you want a more established codebase and its paid extension ecosystem for specific integrations.'),
            'sections' => [
                [
                    'heading' => __('How do Relaticle and EspoCRM pricing compare?'),
                    'body' => __('Both are AGPL-3.0, and both can be self-hosted for free. On the hosted side the models split: Relaticle charges :relaticlePricing, while EspoCRM prices per user with seat minimums (:espocrmPricing), so a small team pays for seats it may not fill. Self-hosters should also note the difference in what "free" covers. Relaticle gates no feature in a self-hosted install, while EspoCRM sells paid extensions on top of its free core.', ['relaticlePricing' => $relaticle['pricing'], 'espocrmPricing' => $competitor['pricing']]),
                ],
                [
                    'heading' => __('Which one has built-in AI and MCP support?'),
                    'body' => __('Relaticle ships :relaticleAi. Every write the built-in assistant proposes is a human-approved card, so the assistant never changes customer data on its own. EspoCRM\'s AI: :espocrmAi. Both give AI agents access to CRM data over MCP. The difference is the assistant: Relaticle includes one, and EspoCRM sells its AI as an add-on.', ['relaticleAi' => $relaticle['ai'], 'espocrmAi' => $competitor['ai']]),
                ],
                [
                    'heading' => __('What does self-hosting each one take?'),
                    'body' => __('Relaticle runs on :relaticleStack. EspoCRM runs on :espocrmStack. Both are single-server-friendly PHP deployments, so neither demands a heavy operations setup. On hosting economics the split is :relaticleSelfHost for Relaticle versus :espocrmSelfHost for EspoCRM.', ['relaticleStack' => $relaticle['stack'], 'espocrmStack' => $competitor['stack'], 'relaticleSelfHost' => lcfirst($relaticle['self_host']), 'espocrmSelfHost' => lcfirst($competitor['self_host'])]),
                ],
                [
                    'heading' => __('Who has the bigger community and ecosystem?'),
                    'body' => __('EspoCRM has the longer track record: :espocrmStars GitHub stars and :espocrmContributors contributors, against Relaticle\'s :relaticleStars stars and :relaticleContributors. EspoCRM\'s extensibility is :espocrmExtensibility. Relaticle\'s is :relaticleExtensibility.', [
                        'espocrmStars' => number_format($competitor['stars']),
                        'espocrmContributors' => $competitor['contributors'],
                        'relaticleStars' => number_format($relaticle['stars']),
                        'relaticleContributors' => $relaticle['contributors'].' contributors',
                        'espocrmExtensibility' => $competitor['extensibility'],
                        'relaticleExtensibility' => $relaticle['extensibility'],
                    ]),
                ],
            ],
        ],
    ][$competitorSlug];

    $titles = [
        'twenty' => __('Relaticle vs Twenty'),
        'espocrm' => __('Relaticle vs EspoCRM'),
    ];

    /*
     * The <title> tag, deliberately not the same string as the H1. These pages rank
     * (avg position 6-12) but earn no clicks, and the queries that do convert read
     * like "open source ai crm", so the search-result title leads with that language
     * while the on-page heading stays short. Kept under ~60 characters so Google
     * does not truncate it, and free of the "- Relaticle" suffix the layout used to
     * get, which spent characters repeating a brand the title already opens with.
     */
    $metaTitles = [
        'twenty' => __('Relaticle vs Twenty: Open Source CRM Compared'),
        'espocrm' => __('Relaticle vs EspoCRM: Open Source CRM Compared'),
    ];

    /*
     * No competitor prices or counts here on purpose: those live in
     * competitor-facts.php so `gtm:stale-facts` can age them out. A number copied
     * into a meta description is a second source of truth nothing checks.
     */
    $descriptions = [
        'twenty' => __('Both are open source and self-hostable. Relaticle bills one flat rate for unlimited users and runs AI and MCP self-hosted; Twenty prices per seat.'),
        'espocrm' => __('Both are AGPL-3.0 PHP CRMs you can self-host. Relaticle adds a built-in AI assistant and a first-party MCP server, and bills flat; EspoCRM prices per seat.'),
    ];

    $title = $titles[$competitorSlug];
    $metaTitle = $metaTitles[$competitorSlug];
    $description = $descriptions[$competitorSlug];
    $factsVerifiedDate = \Carbon\CarbonImmutable::parse($relaticle['verified']);
    $factsVerifiedAt = $factsVerifiedDate->format('F j, Y');

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
        $aboutEntity = function (array $facts): \Spatie\SchemaOrg\SoftwareApplication {
            $application = \Spatie\SchemaOrg\Schema::softwareApplication()
                ->name($facts['name'])
                ->url($facts['source_urls']['website'])
                ->applicationCategory('BusinessApplication');

            if (isset($facts['source_urls']['repository'])) {
                $application->sameAs([$facts['source_urls']['repository']]);
            }

            return $application;
        };

        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($page) => $page
                ->name($title)
                ->description($description)
                ->url(url()->current())
                ->dateModified($factsVerifiedDate)
                ->about([$aboutEntity($relaticle), $aboutEntity($competitor)]))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name($title)->item(url()->current()),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
