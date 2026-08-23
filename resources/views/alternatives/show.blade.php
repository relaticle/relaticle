@php
    /**
     * @var array<string, mixed> $relaticle
     * @var array<string, mixed> $competitor
     * @var string $competitorSlug
     */
    $copy = [
        'attio' => [
            'badge' => __('Alternative'),
            'opening' => __('Attio is a well-regarded closed-source SaaS CRM with strong data-model flexibility — but there\'s no self-hosting option, and its AI is a proprietary, Cloud-only feature. If you want to own your data and self-host the same AI and MCP tooling your team uses in production, Relaticle is the alternative. If you specifically need Attio\'s enrichment and research features today, they\'re more mature there than anywhere Relaticle currently offers.'),
            'sections' => [
                [
                    'heading' => __('License & pricing'),
                    'body' => __('Attio is closed-source (:attioPricing). Relaticle is AGPL-3.0 — self-host free forever, or pay a flat :relaticlePricing on the hosted plan.', ['attioPricing' => $competitor['pricing'], 'relaticlePricing' => $relaticle['pricing']]),
                ],
                [
                    'heading' => __('AI capabilities'),
                    'body' => __('Attio\'s AI: :attioAi — available only on their SaaS. Relaticle\'s AI: :relaticleAi.', ['attioAi' => $competitor['ai'], 'relaticleAi' => $relaticle['ai']]),
                ],
                [
                    'heading' => __('Data ownership & deployment'),
                    'body' => __('Attio: :attioSelfHost — your data lives on their infrastructure. Relaticle: :relaticleStack. :relaticleSelfHost.', ['attioSelfHost' => $competitor['self_host'], 'relaticleStack' => $relaticle['stack'], 'relaticleSelfHost' => $relaticle['self_host']]),
                ],
                [
                    'heading' => __('Extensibility'),
                    'body' => __('Attio\'s extensibility: :attioExtensibility. Relaticle\'s extensibility: :relaticleExtensibility.', ['attioExtensibility' => $competitor['extensibility'], 'relaticleExtensibility' => $relaticle['extensibility']]),
                ],
            ],
        ],
        'hubspot' => [
            'badge' => __('Alternative'),
            'opening' => __('HubSpot\'s free CRM works for very small teams, and its paid Hubs bundle marketing, sales, and service automation well beyond core CRM — that breadth is real, and if you need an integrated marketing or service suite today, HubSpot\'s is more mature. If you want a self-hosted, open-source CRM with built-in AI and flat pricing that doesn\'t grow with every seat you add, Relaticle is the alternative.'),
            'sections' => [
                [
                    'heading' => __('License & pricing'),
                    'body' => __('HubSpot is closed-source (:hubspotPricing) — cost climbs fast once you add paid Hubs and seats. Relaticle is AGPL-3.0, with a flat :relaticlePricing on the hosted plan and no per-Hub upsells.', ['hubspotPricing' => $competitor['pricing'], 'relaticlePricing' => $relaticle['pricing']]),
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
        'attio' => __('Attio Alternative'),
        'hubspot' => __('HubSpot Alternative'),
    ];

    /*
     * The <title> tag, deliberately not the same string as the H1. "Open source"
     * leads because that is the language of the queries these pages already rank
     * for, and the phrasing mirrors them directly rather than restating the H1.
     * Kept under ~60 characters so Google does not truncate it.
     */
    $metaTitles = [
        'attio' => __('Open Source Attio Alternative, Self-Hosted | Relaticle'),
        'hubspot' => __('Open Source HubSpot Alternative, Self-Hosted | Relaticle'),
    ];

    /*
     * Structural facts only, no prices or counts: those live in
     * competitor-facts.php so `gtm:stale-facts` can age them out.
     */
    $descriptions = [
        'attio' => __('Attio is closed-source with no self-hosting option. Relaticle is AGPL-3.0, self-hostable free, flat-priced for unlimited users, with built-in AI and MCP.'),
        'hubspot' => __('HubSpot is closed-source with no self-hosting option. Relaticle is AGPL-3.0, self-hostable free, flat-priced for unlimited users, with built-in AI and MCP.'),
    ];

    $title = $titles[$competitorSlug];
    $metaTitle = $metaTitles[$competitorSlug];
    $description = $descriptions[$competitorSlug];
    $factsVerifiedAt = \Carbon\CarbonImmutable::parse($relaticle['verified'])->format('F j, Y');
@endphp

<x-guest-layout
    :title="$metaTitle"
    :description="$description"
    :ogTitle="$metaTitle"
>
    <section class="relative pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-3xl mx-auto px-6 lg:px-8">

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-arrow-left-right-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
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
                    {{ __('Relaticle vs :name at a glance', ['name' => $competitor['name']]) }}
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

            {{-- Migration path --}}
            <div class="rounded-2xl border border-gray-200/80 bg-white dark:border-white/[0.06] dark:bg-white/[0.02] px-6 py-8 mb-16">
                <h2 class="font-display text-xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white mb-3">
                    {{ __('Migrating from :name', ['name' => $competitor['name']]) }}
                </h2>
                <p class="text-sm md:text-base text-gray-600 dark:text-gray-400 leading-relaxed mb-4">
                    {{ __('Export your companies, people, and deals from :name as CSV, then bring them into Relaticle with the built-in import wizard — map columns, preview matched records, and fix errors before anything is created.', ['name' => $competitor['name']]) }}
                </p>
                <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400 list-disc pl-5">
                    <li>{{ __('Export each record type to CSV from :name.', ['name' => $competitor['name']]) }}</li>
                    <li>{{ __('Import the CSV with Relaticle\'s import wizard — no third-party migration tool needed.') }}</li>
                    <li>{{ __('Need programmatic access instead? Relaticle\'s REST API and MCP server can create and update records directly.') }}</li>
                </ul>
                <a href="{{ url('/help/import') }}" class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary dark:text-primary-400 hover:underline">
                    <x-ri-upload-2-line class="h-4 w-4"/>
                    {{ __('Read the import guide') }}
                </a>
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

                {{-- The two claims this page leans on hardest, self-hosting and the AI
                     layer, each have a page of their own. Linked here rather than inside
                     the copy above, which is all translated strings. --}}
                <p class="mt-6 text-xs text-gray-500 dark:text-gray-400">
                    <a href="{{ route('selfHosted') }}" class="underline decoration-gray-300 dark:decoration-gray-600 underline-offset-2 hover:text-primary dark:hover:text-primary-400">{{ __('See self-hosting options') }}</a>
                    <span class="px-1.5 text-gray-300 dark:text-gray-600" aria-hidden="true">&middot;</span>
                    <a href="{{ route('ai') }}" class="underline decoration-gray-300 dark:decoration-gray-600 underline-offset-2 hover:text-primary dark:hover:text-primary-400">{{ __('Meet :name, the built-in AI assistant', ['name' => config('chat.assistant_name')]) }}</a>
                </p>
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
