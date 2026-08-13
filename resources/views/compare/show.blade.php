@php
    /**
     * @var array<string, mixed> $relaticle
     * @var array<string, mixed> $competitor
     * @var string $competitorSlug
     */
    $copy = [
        'twenty' => [
            'badge' => __('Comparison'),
            'opening' => __('Relaticle and Twenty are both AGPL-3.0, self-hostable CRMs, so the choice comes down to what you\'re optimizing for. Pick Relaticle if you want AI tooling — chat and MCP — that runs fully self-hosted and flat pricing that doesn\'t grow with headcount. Pick Twenty if you want the larger open-source community or prefer a Node/NestJS stack over Laravel and PHP.'),
            'sections' => [
                [
                    'heading' => __('License & pricing'),
                    'body' => __('Both projects publish under AGPL-3.0 — Twenty adds a Twenty Application Exception that license-tags its enterprise files in-repo. Relaticle charges a flat :relaticlePricing. Twenty prices per user (:twentyPricing), so its bill scales with every seat you add.', ['relaticlePricing' => $relaticle['pricing'], 'twentyPricing' => $competitor['pricing']]),
                ],
                [
                    'heading' => __('AI and MCP tooling'),
                    'body' => __('Relaticle ships :relaticleAi. Twenty\'s offering is :twentyAi — self-hosters get the core CRM, but the AI tooling itself is Cloud-oriented.', ['relaticleAi' => $relaticle['ai'], 'twentyAi' => $competitor['ai']]),
                ],
                [
                    'heading' => __('Tech stack & deployment'),
                    'body' => __('Relaticle runs on :relaticleStack. Twenty runs on :twentyStack — more moving parts to operate yourself if you self-host.', ['relaticleStack' => $relaticle['stack'], 'twentyStack' => $competitor['stack']]),
                ],
                [
                    'heading' => __('Community & extensibility'),
                    'body' => __('Twenty has the bigger open-source footprint — :twentyStars GitHub stars and :twentyContributors contributors, against Relaticle\'s :relaticleStars stars and :relaticleContributors. Twenty also ships :twentyExtensibility. Relaticle\'s extensibility is :relaticleExtensibility.', [
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
                    'heading' => __('License & pricing'),
                    'body' => __('Both are AGPL-3.0. Relaticle charges a flat :relaticlePricing. EspoCRM prices per user with seat minimums (:espocrmPricing).', ['relaticlePricing' => $relaticle['pricing'], 'espocrmPricing' => $competitor['pricing']]),
                ],
                [
                    'heading' => __('AI and MCP tooling'),
                    'body' => __('Relaticle ships :relaticleAi. EspoCRM currently has :espocrmAi.', ['relaticleAi' => $relaticle['ai'], 'espocrmAi' => lcfirst($competitor['ai'])]),
                ],
                [
                    'heading' => __('Tech stack & deployment'),
                    'body' => __('Relaticle runs on :relaticleStack. EspoCRM runs on :espocrmStack — both are single-server-friendly PHP deployments.', ['relaticleStack' => $relaticle['stack'], 'espocrmStack' => $competitor['stack']]),
                ],
                [
                    'heading' => __('Community & extensibility'),
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
    $factsVerifiedAt = \Carbon\CarbonImmutable::parse($relaticle['verified'])->format('F j, Y');
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

            <p class="text-xs text-gray-400 dark:text-gray-500 mb-16">
                {{ __('Facts verified :date. Sources are dated in the underlying facts file — see :repo.', ['date' => $factsVerifiedAt, 'repo' => 'github.com/relaticle/relaticle']) }}
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
</x-guest-layout>
