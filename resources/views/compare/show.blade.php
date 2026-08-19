@php
    /**
     * @var array<string, mixed> $relaticle
     * @var array<string, mixed> $competitor
     * @var string $competitorSlug
     */
    $copy = [
        'twenty' => [
            'badge' => __('Comparison'),
            'opening' => __('Relaticle and Twenty CRM are both AGPL-3.0, self-hostable CRMs, so the choice comes down to what you\'re optimizing for. Pick Relaticle if you want AI tooling — chat and MCP — that runs fully self-hosted and flat pricing that doesn\'t grow with headcount. Pick Twenty if you want the larger open-source community or prefer a Node/NestJS stack over Laravel and PHP.'),
            'sections' => [
                [
                    'heading' => __('How do Relaticle and Twenty pricing compare?'),
                    'body' => __('Relaticle charges :relaticlePricing — the bill is the same whether two people use the workspace or two hundred. Twenty prices per user (:twentyPricing), so its bill scales with every seat you add, and the gap between the two models widens as your team grows. On licensing, both projects publish under AGPL-3.0; Twenty adds a Twenty Application Exception that license-tags its enterprise files in-repo, which means some functionality stays legally reserved even in a self-hosted deploy. Relaticle has no equivalent carve-out: the self-hosted and hosted versions run the same codebase with no feature gating.', ['relaticlePricing' => $relaticle['pricing'], 'twentyPricing' => $competitor['pricing']]),
                ],
                [
                    'heading' => __('Which one runs AI and MCP self-hosted?'),
                    'body' => __('Relaticle ships :relaticleAi. Every write the built-in assistant proposes renders as an approval card — old value to new value, per field — and executes only when a human accepts it, so the AI layer is safe to point at real customer data. Twenty\'s offering is :twentyAi — self-hosters get the core CRM, but the AI tooling itself is Cloud-oriented, and its public self-hosting documentation does not describe an equivalent out-of-the-box MCP setup. If self-hosted AI is the requirement, this is the sharpest difference between the two products.', ['relaticleAi' => $relaticle['ai'], 'twentyAi' => $competitor['ai']]),
                ],
                [
                    'heading' => __('What does self-hosting each one take?'),
                    'body' => __('Relaticle runs on :relaticleStack — one process to operate, and :relaticleSelfHost. Twenty runs on :twentyStack, which means more moving parts to provision, monitor, and upgrade yourself. Twenty\'s position is :twentySelfHost. If you want the smallest possible operational footprint, a single Laravel server is hard to beat; if you already operate Node services with Redis and Postgres, Twenty\'s stack will feel familiar.', ['relaticleStack' => $relaticle['stack'], 'relaticleSelfHost' => lcfirst($relaticle['self_host']), 'twentyStack' => $competitor['stack'], 'twentySelfHost' => lcfirst($competitor['self_host'])]),
                ],
                [
                    'heading' => __('Who has the bigger community and ecosystem?'),
                    'body' => __('Twenty CRM has the bigger open-source footprint — :twentyStars GitHub stars and :twentyContributors contributors on the twentyhq/twenty repository, against Relaticle\'s :relaticleStars stars and :relaticleContributors. Twenty also ships :twentyExtensibility. Relaticle\'s extensibility is :relaticleExtensibility. If community size, third-party extensions, or the confidence of a large existing user base decides it for you, that point goes to Twenty; if you want a smaller codebase you can read end to end and extend directly, that favors Relaticle.', [
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
            'opening' => __('Relaticle and EspoCRM are both open-source, AGPL-3.0, self-hosted-first CRMs built to run on a single PHP server. Pick Relaticle if built-in AI — a chat assistant plus a 32-tool MCP server — matters to you, since EspoCRM ships neither today. Pick EspoCRM if you want a more established codebase and its paid extension ecosystem for specific integrations.'),
            'sections' => [
                [
                    'heading' => __('How do Relaticle and EspoCRM pricing compare?'),
                    'body' => __('Both are AGPL-3.0, and both can be self-hosted for free. On the hosted side the models split: Relaticle charges :relaticlePricing, while EspoCRM prices per user with seat minimums (:espocrmPricing), so a small team pays for seats it may not fill. Self-hosters should also note the difference in what "free" covers — Relaticle ships one codebase with no feature gating, while EspoCRM sells paid extensions on top of its free core.', ['relaticlePricing' => $relaticle['pricing'], 'espocrmPricing' => $competitor['pricing']]),
                ],
                [
                    'heading' => __('Which one has built-in AI and MCP support?'),
                    'body' => __('Relaticle ships :relaticleAi. Every write the built-in assistant proposes is a human-approved card, so the AI can work real customer data without acting unilaterally. EspoCRM\'s AI: :espocrmAi. If AI tooling matters to your evaluation, this is the deciding dimension — EspoCRM simply does not compete on it today.', ['relaticleAi' => $relaticle['ai'], 'espocrmAi' => $competitor['ai']]),
                ],
                [
                    'heading' => __('What does self-hosting each one take?'),
                    'body' => __('Relaticle runs on :relaticleStack. EspoCRM runs on :espocrmStack — both are single-server-friendly PHP deployments, so neither demands a heavy operations setup. On hosting economics the split is :relaticleSelfHost for Relaticle versus :espocrmSelfHost for EspoCRM.', ['relaticleStack' => $relaticle['stack'], 'espocrmStack' => $competitor['stack'], 'relaticleSelfHost' => lcfirst($relaticle['self_host']), 'espocrmSelfHost' => lcfirst($competitor['self_host'])]),
                ],
                [
                    'heading' => __('Who has the bigger community and ecosystem?'),
                    'body' => __('EspoCRM has the longer track record — :espocrmStars GitHub stars and :espocrmContributors contributors, against Relaticle\'s :relaticleStars stars and :relaticleContributors. EspoCRM\'s extensibility is :espocrmExtensibility. Relaticle\'s is :relaticleExtensibility.', [
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

    $descriptions = [
        'twenty' => __('Relaticle vs Twenty compared on license, pricing, GitHub stars, contributors, tech stack, and AI/MCP self-hosting, so you can pick the right open-source CRM.'),
        'espocrm' => __('Relaticle vs EspoCRM compared on license, pricing, tech stack, and AI tooling, so you can pick the self-hosted PHP CRM with AI chat and MCP support today.'),
    ];

    $title = $titles[$competitorSlug];
    $description = $descriptions[$competitorSlug];
    $factsVerifiedDate = \Carbon\CarbonImmutable::parse($relaticle['verified']);
    $factsVerifiedAt = $factsVerifiedDate->format('F j, Y');

    $primarySources = collect([$relaticle, $competitor])
        ->flatMap(fn (array $facts): array => array_filter([
            ['label' => __(':name pricing', ['name' => $facts['name']]), 'url' => $facts['source_urls']['pricing']],
            isset($facts['source_urls']['repository'])
                ? ['label' => __(':name on GitHub', ['name' => $facts['name']]), 'url' => $facts['source_urls']['repository']]
                : null,
        ]));
@endphp

<x-guest-layout
    :title="$title . ' - Relaticle'"
    :description="$description"
    :ogTitle="$title . ' - Relaticle'"
>
    <section class="relative pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-3xl mx-auto px-6 lg:px-8">

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-scales-3-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ $copy['badge'] }}</span>
                </div>
            </div>

            {{-- Header --}}
            <div class="text-center max-w-2xl mx-auto mb-10">
                <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                    {{ $title }}
                </h1>
            </div>

            {{-- Answer-first opening paragraph --}}
            <p class="text-base md:text-lg text-gray-600 dark:text-gray-400 leading-relaxed mb-12">
                {{ $copy['opening'] }}
            </p>

            {{-- Comparison table --}}
            <div class="mb-16">
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white mb-6">
                    {{ __(':title at a glance', ['title' => $title]) }}
                </h2>
                @include('partials.comparison-facts-table')
            </div>

            {{-- Prose sections per dimension --}}
            <div class="space-y-10 mb-16">
                @foreach ($copy['sections'] as $section)
                    <div>
                        <h2 class="font-display text-xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white mb-3">
                            {{ $section['heading'] }}
                        </h2>
                        <p class="text-sm md:text-base text-gray-600 dark:text-gray-400 leading-relaxed">
                            {{ $section['body'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">
                {{ __('Facts verified :date. Sources are dated in the underlying facts file — see :repo.', ['date' => $factsVerifiedAt, 'repo' => 'github.com/relaticle/relaticle']) }}
            </p>

            <p class="text-xs text-gray-400 dark:text-gray-500 mb-16">
                {{ __('Primary sources:') }}
                @foreach ($primarySources as $source)
                    <a href="{{ $source['url'] }}" rel="nofollow noopener" target="_blank" class="underline decoration-gray-300 dark:decoration-gray-600 underline-offset-2 hover:text-gray-600 dark:hover:text-gray-300">{{ $source['label'] }}</a>{{ $loop->last ? '.' : ',' }}
                @endforeach
            </p>

            {{-- CTA --}}
            <div class="rounded-2xl border border-gray-200/80 bg-white dark:border-white/[0.06] dark:bg-white/[0.02] px-6 py-8 text-center">
                <h2 class="font-display text-xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white mb-3">
                    {{ __('Try Relaticle yourself') }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed mb-6 max-w-md mx-auto">
                    {{ __('Self-host it free under AGPL-3.0, or start on the hosted plan — both run the same open-source codebase.') }}
                </p>
                <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                    <x-marketing.button href="{{ route('register') }}">
                        {{ __('Start for free') }}
                    </x-marketing.button>
                    <x-marketing.button variant="secondary" href="{{ route('contact') }}">
                        {{ __('Get in touch') }}
                    </x-marketing.button>
                </div>
            </div>

        </div>
    </section>

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
</x-guest-layout>
