@php
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $proPlan = \App\Enums\Plan::Pro;
    $scalePlan = \App\Enums\Plan::Scale;
    $scaleCredits = number_format($scalePlan->credits());
    $enterprisePrice = number_format(config('relaticle.enterprise.starting_price_yearly'));
    $pageTitle = __('Pricing - $:price/mo flat, unlimited users - Relaticle', ['price' => $proPlan->yearlyPricePerMonth()]);
@endphp

<x-cloud::guest-layout
    :title="$pageTitle"
    :description="$billingActive
        ? __('Cloud Pro is $:pro/mo and Cloud Scale $:scale/mo, billed yearly. Enterprise from $:price/year. Unlimited users and records on every plan.', ['pro' => $proPlan->yearlyPricePerMonth(), 'scale' => $scalePlan->yearlyPricePerMonth(), 'price' => $enterprisePrice])
        : __('Self-host for free, or let us run Relaticle for you. Unlimited users and records. No per-seat pricing.')"
    :ogTitle="$pageTitle"
    framed
>
    @php
        $proCredits = number_format(\App\Enums\Plan::Pro->credits());
        $freeRateLimit = \App\Enums\Plan::Free->rateLimit();
        $proRateLimit = \App\Enums\Plan::Pro->rateLimit();
        $trialDays = \App\Models\Workspace::PRO_TRIAL_DAYS;
        $toolCapableCloudModels = collect(resolve(\Relaticle\Chat\Services\ModelRegistry::class)->offered())
            ->map(fn (\Relaticle\Chat\Support\ModelDescriptor $model): array => [
                'label' => $model->displayLabel(),
                'min_plan' => $model->minPlan->value,
                'credit_multiplier' => $model->creditMultiplier,
            ]);
        $freeCloudModels = $toolCapableCloudModels->where('min_plan', 'free')->pluck('label')->join(', ', ' and ');
        $paidCloudModels = $toolCapableCloudModels->where('min_plan', 'pro')->pluck('label')->join(', ', ' and ');
        $multiplierClauses = $toolCapableCloudModels
            ->groupBy(fn (array $model): string => rtrim(rtrim(number_format($model['credit_multiplier'], 2, '.', ''), '0'), '.'))
            ->map(fn (\Illuminate\Support\Collection $group): string => $group->pluck('label')->join(', ', ' and '));

        $multiplierClauses->put('1', $multiplierClauses->has('1')
            ? __(':models and self-hosted models', ['models' => $multiplierClauses->get('1')])
            : __('self-hosted models'));

        $creditMultiplierList = $multiplierClauses
            ->sortKeys(SORT_NUMERIC)
            ->map(fn (string $models, string $multiplier): string => __(':multiplierx for :models', ['multiplier' => $multiplier, 'models' => $models]))
            ->join('; ');
        $sortedByCost = $toolCapableCloudModels->sortBy('credit_multiplier')->values();
        $cheapestModel = $sortedByCost->first()['label'] ?? __('a 1x model');
        $dearestEntry = $sortedByCost->last() ?? ['label' => __('a higher-multiplier model'), 'credit_multiplier' => 1.0];
        $dearestReplyCost = max(1, (int) ceil($dearestEntry['credit_multiplier'] + 1.0));

        $creditFaqAnswer = __(
            'Credit cost is not flat. It depends on the model and on how much work a reply does. Each message costs its model\'s credit multiplier (:multipliers), plus 0.5 credits for every tool call the assistant makes while answering, such as searching, creating, or updating a record. The total rounds up to the next whole credit, with a 1-credit minimum. A simple reply from :cheapestModel with no tool calls costs 1 credit; a reply from :dearestModel that touches two records costs :dearestCost. Using the REST API or the MCP server directly, outside the built-in chat, never touches your credit balance.',
            [
                'multipliers' => $creditMultiplierList,
                'cheapestModel' => $cheapestModel,
                'dearestModel' => $dearestEntry['label'],
                'dearestCost' => $dearestReplyCost,
            ]
        );
        $paidPlanLabel = __('Cloud Pro');
        $modelsUnlockAnswer = trim(implode(' ', array_filter([
            $freeCloudModels === ''
                ? __('Every plan can use any self-hosted model you connect yourself.')
                : __('Every plan can use :freeModels and any self-hosted model you connect yourself.', ['freeModels' => $freeCloudModels]),
            $paidCloudModels === ''
                ? ''
                : __(':paidPlan additionally unlocks the higher-multiplier models: :paidModels.', ['paidModels' => $paidCloudModels, 'paidPlan' => $paidPlanLabel]),
        ])));

        $rateLimitAnswer = $billingActive
            ? __(
                'Yes. A per-minute cap is shared across the whole workspace, not per person: :free messages/minute on Free, :pro/minute on Cloud Pro, :scale/minute on Cloud Scale. It exists to stop runaway usage, not to constrain normal work.',
                ['free' => $freeRateLimit, 'pro' => $proRateLimit, 'scale' => $scalePlan->rateLimit()]
            )
            : __(
                'Yes. A per-minute cap is shared across the whole workspace, not per person: :free messages/minute on Free, :pro/minute on :paidPlan. It exists to stop runaway usage, not to constrain normal work.',
                ['free' => $freeRateLimit, 'pro' => $proRateLimit, 'paidPlan' => $paidPlanLabel]
            );

        $selfHostedCreditAnswer = __('Yes. Self-hosted installs do not meter AI credits, so there is no credit limit. You add your own provider key or local model and pay that provider directly. Only a per-minute send limit applies, and the operator sets it.');

        if ($billingActive) {
            $selfHostedCreditAnswer .= ' '.__(
                'On Relaticle Cloud, new workspaces start a :days-day Cloud Pro trial with :proCredits credits. Hosted access pauses when the trial ends without a subscription.',
                ['days' => $trialDays, 'proCredits' => $proCredits]
            );

            $hostedPriceCell = __('$:yearlyPerMonth/mo per workspace ($:yearly billed yearly, or $:monthly/mo billed monthly)', [
                'yearlyPerMonth' => $proPlan->yearlyPricePerMonth(),
                'yearly' => $proPlan->yearlyPrice(),
                'monthly' => $proPlan->monthlyPrice(),
            ]);
            $hostedUpdatesCell = __('Managed by Relaticle. No self-hosted maintenance required');
            $hostedPlanAnswer = __(
                'Cloud Pro is :price and includes unlimited users and records, every supported AI model from :cheapestModel up to :dearestModel, the REST API, the MCP server, and email support. Each workspace gets a :credits-credit monthly AI allowance; how far it goes depends on the model and how many tool calls each reply makes (see "What counts as an AI credit?" below). As a reference point, :credits credits covers roughly :credits simple :cheapestModel replies, or around :dearestReplies :dearestModel replies before tool calls. Cloud Scale raises the allowance to :scaleCredits credits, lifts the mailbox cap to :scaleMailboxes, and triples REST API and MCP throughput for $:scalePrice/mo billed yearly. New workspaces start on a :days-day trial automatically, with no card required.',
                [
                    'price' => $hostedPriceCell,
                    'scaleCredits' => $scaleCredits,
                    'scaleMailboxes' => $scalePlan->connectedMailboxes(),
                    'scalePrice' => $scalePlan->yearlyPricePerMonth(),
                    'credits' => $proCredits,
                    'cheapestModel' => $cheapestModel,
                    'dearestModel' => $dearestEntry['label'],
                    'dearestReplies' => number_format(intdiv((int) \App\Enums\Plan::Pro->credits(), max(1, (int) ceil($dearestEntry['credit_multiplier'])))),
                    'days' => $trialDays,
                ]
            );
            $planLimitAnswer = __(
                'CRM data itself is never capped. Every plan supports unlimited users, companies, people, opportunities, tasks, and notes. The only metered resource is :name, the AI assistant: Cloud Pro\'s :credits credits a month and Cloud Scale\'s :scaleCredits reset each billing (or trial) period. Once they are used up, :name declines new chat requests until the next reset. Paid workspaces can buy a prepaid credit top-up instead of waiting. Nothing else in the CRM is affected.',
                ['credits' => $proCredits, 'scaleCredits' => $scaleCredits, 'name' => config('chat.assistant_name')]
            );
        } else {
            $hostedPriceCell = __('$0/mo per workspace');
            $hostedUpdatesCell = __('Zero-downtime updates and automatic daily backups, handled for you');
            $hostedPlanAnswer = __('The hosted Cloud plan is $0/mo and includes unlimited users and data, the MCP server, the REST API, custom fields, multiple workspaces, zero-downtime updates, automatic daily backups, and email support. No credit card is required.');
            $planLimitAnswer = __(
                'CRM data itself is never capped on any plan. Every workspace supports unlimited users, companies, people, opportunities, tasks, and notes, whether you\'re self-hosting or on the hosted Cloud plan. :name, the AI assistant, does not meter credits on a self-hosted install, so no credit limit applies. See "Are self-hosted installs exempt from AI credit limits?" below.',
                ['name' => config('chat.assistant_name')]
            );
        }
    @endphp

    @php
        $trialFaq = [
            __('What happens after my trial ends?'),
            __(
                'New workspaces start a :days-day trial automatically, with no card required. If a payment method isn\'t added before the trial ends, hosted access pauses and you\'re redirected to the billing page to subscribe. Self-hosting the same open-source CRM is always available as a fallback.',
                ['days' => $trialDays]
            ),
        ];

        $faqGroups = array_filter([
            __('Plans and billing') => [
                [
                    __('Do you charge per seat?'),
                    __('No. Relaticle has never charged per seat. Every plan, self-hosted or hosted, is priced per workspace, so you can add as many teammates as you need without the bill changing.'),
                ],
                [__("What's included in the hosted plan?"), $hostedPlanAnswer],
                ...($billingActive ? [$trialFaq] : []),
                [__('What happens when I hit a plan limit?'), $planLimitAnswer],
            ],
            __('AI credits') => [
                ...($billingActive ? [
                    [__('What counts as an AI credit?'), $creditFaqAnswer],
                    [__('Which AI models does my plan unlock?'), $modelsUnlockAnswer],
                    [__('Is there a message rate limit?'), $rateLimitAnswer],
                ] : []),
                [__('Are self-hosted installs exempt from AI credit limits?'), $selfHostedCreditAnswer],
            ],
            __('Self-hosting') => [
                [
                    __('Is Relaticle really free to self-host?'),
                    __('Yes. Self-hosting is fully open source under the AGPL-3.0 license, with unlimited users and unlimited records and no credit card required. Deploy it yourself with the published Docker Compose file. Your data stays on your own server the entire time.'),
                ],
                [
                    __('Can I switch between self-hosted and cloud?'),
                    __('Yes. Both options run the same open-source CRM and store your records the same way, so neither locks you in. Companies, people, opportunities, tasks, and notes each have a built-in CSV export, and the import wizard on the other side accepts CSV. Moving between a self-hosted install and the hosted plan is a standard export and re-import, not a proprietary migration.'),
                ],
            ],
            __('Enterprise') => $billingActive ? [
                [__('cloud::billing.enterprise.faq_title'), __('cloud::billing.enterprise.faq_body', ['price' => $enterprisePrice])],
                [__('cloud::billing.enterprise.timeline_title'), __('cloud::billing.enterprise.timeline_body')],
            ] : [],
        ]);
    @endphp

    <x-cloud::marketing.hero>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Pricing') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            @if($billingActive)
                {{ __('Pay per workspace.') }}
                <span class="text-gray-500 dark:text-gray-400">{{ __('Never per seat.') }}</span>
            @else
                {{ __('No per-seat pricing.') }}
                <span class="text-gray-500 dark:text-gray-400">{{ __('Ever.') }}</span>
            @endif
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ $billingActive
                    ? __('Unlimited users and records on every plan. Run it yourself, or let us set it up for you.')
                    : __('Unlimited users. Unlimited records. Self-host for free, or let us run it for you.') }}
            </p>
        </x-slot:aside>

        @if($billingActive)
            @include('cloud::marketing.partials.pricing-plans')
        @else
            @include('cloud::marketing.partials.pricing-legacy')
        @endif
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame id="compare">
        @if($billingActive)
            <x-cloud::marketing.frame-header :label="__('Compare plans')" :lead="__('Choose how you get started.')">
                {{ __('Same CRM, different levels of help.') }}
            </x-cloud::marketing.frame-header>

            @include('cloud::marketing.partials.pricing-comparison')
        @else
            <x-cloud::marketing.frame-header :label="__('Compare')" :lead="__('Self-hosted or hosted: how to choose.')">
                {{ __('The same open-source CRM either way.') }}
            </x-cloud::marketing.frame-header>

            @php
                $legacyRows = [
                    [__('Price'), __('Free forever'), $hostedPriceCell],
                    [__('Data ownership'), __('Stays on your own infrastructure'), __('Stored on Relaticle-managed infrastructure')],
                    [__('Updates'), __('You pull and deploy new Docker images yourself'), $hostedUpdatesCell],
                    [__('Getting help'), __('Community support on Discord'), __('Email support')],
                ];
            @endphp

            <p class="type-prose border-rail border-b px-6 py-6 text-gray-500 md:px-10 dark:text-gray-400">
                {{ __('Both options run the same open-source CRM, with unlimited users and unlimited records on every plan. The real differences are who operates the server and how AI usage is metered.') }}
            </p>

            <dl class="bg-rail grid gap-px md:grid-cols-4">
                @foreach($legacyRows as [$topic, $selfHosted, $hosted])
                    <div class="bg-surface p-6 md:p-8">
                        <dt class="type-label text-gray-500 dark:text-gray-400">{{ $topic }}</dt>
                        <dd class="mt-4 space-y-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                            <p><span class="font-medium text-gray-950 dark:text-white">{{ __('Self-Hosted') }}:</span> {{ $selfHosted }}</p>
                            <p><span class="font-medium text-gray-950 dark:text-white">{{ __('Hosted') }}:</span> {{ $hosted }}</p>
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="pricing-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Pricing questions, answered') }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ route('contact') }}" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('Get in touch') }}</a>
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                @foreach($faqGroups as $group => $faqs)
                    <div @class(['mt-12' => ! $loop->first])>
                        <x-cloud::marketing.eyebrow>{{ $group }}</x-cloud::marketing.eyebrow>
                        <x-cloud::marketing.faq-accordion :faqs="$faqs" :id-prefix="'pricing-faq-'.$loop->index" />
                    </div>
                @endforeach
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="pricing-cta">
        <div class="bg-warm relative overflow-hidden px-6 py-24 text-center md:py-32">
            <div class="mask-top-corners absolute inset-0 text-gray-400 dark:text-gray-600" aria-hidden="true">
                <svg class="absolute inset-0 size-full">
                    <pattern id="pricing-cta-dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="currentColor"/></pattern>
                    <rect width="100%" height="100%" fill="url(#pricing-cta-dots)"/>
                </svg>
            </div>

            <div class="relative mx-auto max-w-2xl">
                <h2 class="type-title text-gray-950 md:text-5xl dark:text-white">
                    {{ $billingActive ? __('Start with a 14-day trial') : __('Start for free today') }}
                </h2>
                <p class="type-prose mx-auto mt-5 text-gray-500 dark:text-gray-400">
                    {{ $billingActive
                        ? __('No card required. Unlimited users from day one, and the same open-source CRM to self-host whenever you want.')
                        : __('Unlimited users and records. Hosted by us, or on your own server.') }}
                </p>

                <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <x-cloud::marketing.button :href="route('login')" data-analytics-intent="signup" data-analytics-placement="pricing">
                        {{ __('Start for free') }}
                    </x-cloud::marketing.button>
                    @if($billingActive)
                        <x-cloud::marketing.button variant="secondary" :href="route('contact')">
                            {{ __('Talk to us') }}
                        </x-cloud::marketing.button>
                    @else
                        <x-cloud::marketing.button variant="secondary" :href="route('selfHosted')">
                            {{ __('Explore self-hosting') }}
                        </x-cloud::marketing.button>
                    @endif
                </div>

                <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
                    <a href="{{ route('ai') }}" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('Explore :name and the MCP server for Claude and ChatGPT', ['name' => config('chat.assistant_name')]) }}</a>
                    @if(! $billingActive)
                        <span class="mx-2" aria-hidden="true">·</span>
                        <a href="{{ route('contact') }}" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('Questions? Talk to us.') }}</a>
                    @endif
                </p>
            </div>
        </div>
    </x-cloud::marketing.frame>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->product(fn ($product) => $product
                ->name('Relaticle')
                ->description('Open-source CRM with unlimited users and unlimited records on every plan. Self-host free forever under the AGPL-3.0 license, or use the Relaticle-managed hosted plan.')
                ->url(route('pricing'))
                ->image([
                    asset('images/product-preview-16x9.jpg'),
                    asset('images/product-preview-4x3.jpg'),
                    asset('images/product-preview-1x1.jpg'),
                ])
                ->brand(\Spatie\SchemaOrg\Schema::brand()->name('Relaticle'))
                ->offers($billingActive
                    ? [
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Self-hosted')
                            ->price('0')
                            ->priceCurrency('USD')
                            ->availability(\Spatie\SchemaOrg\ItemAvailability::InStock)
                            ->url(route('pricing'))
                            ->description('Free forever, AGPL-3.0 open source, unlimited users and records.'),
                        ...collect([$proPlan, $scalePlan])->map(fn (\App\Enums\Plan $plan): \Spatie\SchemaOrg\Offer => \Spatie\SchemaOrg\Schema::offer()
                            ->name($plan->marketingLabel())
                            ->price((string) $plan->yearlyPricePerMonth())
                            ->priceCurrency('USD')
                            ->availability(\Spatie\SchemaOrg\ItemAvailability::InStock)
                            ->url(route('pricing'))
                            ->description(__('Per workspace, billed yearly at $:yearly/year ($:yearlyPerMonth/mo); $:monthly/mo billed monthly.', [
                                'yearly' => $plan->yearlyPrice(),
                                'yearlyPerMonth' => $plan->yearlyPricePerMonth(),
                                'monthly' => $plan->monthlyPrice(),
                            ])))->all(),
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Enterprise')
                            ->priceSpecification(\Spatie\SchemaOrg\Schema::unitPriceSpecification()
                                ->minPrice(config('relaticle.enterprise.starting_price_yearly'))
                                ->priceCurrency('USD')
                                ->unitText('year'))
                            ->url(route('contact', ['plan' => 'enterprise']))
                            ->description(__('cloud::billing.enterprise.faq_body', ['price' => $enterprisePrice])),
                    ]
                    : [
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Self-hosted')
                            ->price('0')
                            ->priceCurrency('USD')
                            ->availability(\Spatie\SchemaOrg\ItemAvailability::InStock)
                            ->url(route('pricing'))
                            ->description('Free forever, AGPL-3.0 open source, unlimited users and records.'),
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Cloud')
                            ->price('0')
                            ->priceCurrency('USD')
                            ->availability(\Spatie\SchemaOrg\ItemAvailability::InStock)
                            ->url(route('pricing'))
                            ->description('Free hosted plan, managed by Relaticle.'),
                    ]
                )
            );
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
