@php
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $mcpToolCount = \App\Support\CompetitorFacts::mcpToolCount();
    $enterprisePrice = number_format(config('relaticle.enterprise.starting_price_yearly'));
@endphp

<x-guest-layout
    title="Pricing - $19/mo flat, unlimited users - Relaticle"
    :description="$billingActive
        ? __('Cloud Pro is $19/mo billed yearly. Enterprise implementation from $:price/year. Unlimited users and records. Self-host free.', ['price' => $enterprisePrice])
        : __('Self-host for free, or let us run Relaticle for you. Unlimited users and records. No per-seat pricing.')"
    ogTitle="Pricing - $19/mo flat, unlimited users - Relaticle"
>
    <section class="relative overflow-hidden bg-white pb-16 pt-32 dark:bg-gray-950 md:pb-20 md:pt-40">
        <div class="absolute inset-x-0 top-0 h-[36rem] bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_60%_at_50%_0%,black_20%,transparent_100%)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)]"></div>

        <div class="relative mx-auto max-w-3xl px-6 text-center lg:px-8">
            <div class="mb-6 flex justify-center">
                <div class="inline-flex items-center gap-2 rounded-full border border-gray-200/80 bg-white/80 px-3.5 py-1.5 shadow-[0_1px_2px_rgba(0,0,0,0.03)] backdrop-blur-sm dark:border-white/[0.08] dark:bg-white/[0.04]">
                    <x-ri-price-tag-3-line class="h-3.5 w-3.5 text-primary dark:text-primary-400" />
                    <span class="text-[10px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('Pricing') }}</span>
                </div>
            </div>

            <h1 class="text-balance font-display text-4xl font-bold leading-[1.1] tracking-[-0.03em] text-gray-950 dark:text-white sm:text-5xl">
                @if($billingActive)
                    {{ __('One price for your whole team.') }}
                @else
                    {{ __('No per-seat pricing. Ever.') }}
                @endif
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-pretty text-base leading-relaxed text-gray-500 dark:text-gray-400 md:text-lg">
                {{ $billingActive
                    ? __('Unlimited users and records on every plan. Start with Cloud Pro, or work with us on a tailored implementation.')
                    : __('Unlimited users. Unlimited records. Self-host for free, or let us run it for you.') }}
            </p>
        </div>

        <div class="relative mx-auto mt-12 max-w-4xl px-6 lg:px-8">
            @php
                $freeCredits = number_format(\App\Enums\Plan::Free->credits());
                $proCredits = number_format(\App\Enums\Plan::Pro->credits());
                $enterpriseCredits = number_format(\App\Enums\Plan::Enterprise->credits());
                $freeRateLimit = \App\Enums\Plan::Free->rateLimit();
                $proRateLimit = \App\Enums\Plan::Pro->rateLimit();
                $trialDays = \App\Actions\Billing\StartProTrial::TRIAL_DAYS;
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
                $paidPlanLabel = $billingActive ? __('Cloud Pro') : __('a paid plan');
                $modelsUnlockAnswer = trim(implode(' ', array_filter([
                    $freeCloudModels === ''
                        ? __('Every plan can use any self-hosted model you connect yourself.')
                        : __('Every plan can use :freeModels and any self-hosted model you connect yourself.', ['freeModels' => $freeCloudModels]),
                    $paidCloudModels === ''
                        ? ''
                        : __(':paidPlan additionally unlocks the higher-multiplier models: :paidModels.', ['paidModels' => $paidCloudModels, 'paidPlan' => $paidPlanLabel]),
                ])));

                $rateLimitAnswer = __(
                    'Yes. A per-minute cap is shared across the whole workspace, not per person: :free messages/minute on Free, :pro/minute on :paidPlan. It exists to stop runaway usage, not to constrain normal work.',
                    ['free' => $freeRateLimit, 'pro' => $proRateLimit, 'paidPlan' => $paidPlanLabel]
                );

                $selfHostedCreditAnswer = __(
                    'No. Self-hosting does not disable credit metering. Self-hosted installs default to the Free plan\'s :credits-credit monthly allowance, and self-hosters can raise their own workspace\'s plan value in the database, but no plan removes metering entirely: the highest built-in plan caps at :enterpriseCredits credits a month. Changing the plan value alone doesn\'t reset the current period\'s balance. That happens once the existing period ends.',
                    ['credits' => $freeCredits, 'enterpriseCredits' => $enterpriseCredits]
                );

                if ($billingActive) {
                    $selfHostedCreditAnswer .= ' '.__(
                        'New Cloud workspaces start a :days-day Cloud Pro trial with :proCredits credits a month, and hosted access pauses when the trial ends without a subscription.',
                        ['days' => $trialDays, 'proCredits' => $proCredits]
                    );

                    $hostedPriceCell = __('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)');
                    $hostedUpdatesCell = __('Managed by Relaticle. No self-hosted maintenance required');
                    $hostedPlanAnswer = __(
                        'Cloud Pro is :price and includes unlimited users and records, every supported AI model from :cheapestModel up to :dearestModel, the REST API, the 37-tool MCP server, and email support. Each workspace gets a :credits-credit monthly AI allowance; how far it goes depends on the model and how many tool calls each reply makes (see "What counts as an AI credit?" below). As a reference point, :credits credits covers roughly :credits simple :cheapestModel replies, or around :dearestReplies :dearestModel replies before tool calls. New workspaces start on a :days-day trial automatically, with no card required.',
                        [
                            'price' => '$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)',
                            'credits' => $proCredits,
                            'cheapestModel' => $cheapestModel,
                            'dearestModel' => $dearestEntry['label'],
                            'dearestReplies' => number_format(intdiv((int) \App\Enums\Plan::Pro->credits(), max(1, (int) ceil($dearestEntry['credit_multiplier'])))),
                            'days' => $trialDays,
                        ]
                    );
                    $planLimitAnswer = __(
                        'CRM data itself is never capped. Every plan supports unlimited users, companies, people, opportunities, tasks, and notes. The only metered resource is the AI assistant: Cloud Pro\'s :credits credits a month reset each billing (or trial) period. Once they are used up, the assistant declines new chat requests until the next reset. Cloud Pro workspaces can buy a prepaid credit top-up instead of waiting. Nothing else in the CRM is affected.',
                        ['credits' => $proCredits]
                    );
                } else {
                    $hostedPriceCell = __('$0/mo per workspace');
                    $hostedUpdatesCell = __('Zero-downtime updates and automatic daily backups, handled for you');
                    $hostedPlanAnswer = __('The hosted Cloud plan is $0/mo and includes unlimited users and data, the 37-tool MCP server, the REST API, all 22 custom field types, multi-team workspaces, zero-downtime updates, automatic daily backups, and email support. No credit card is required.');
                    $planLimitAnswer = __(
                        'CRM data itself is never capped on any plan. Every workspace supports unlimited users, companies, people, opportunities, tasks, and notes, whether you\'re self-hosting or on the hosted Cloud plan. The AI assistant is metered, though: every workspace defaults to the Free plan\'s :credits credits a month, resetting every calendar month. That includes self-hosted installs; see "Are self-hosted installs exempt from AI credit limits?" below. Once they are used up, the assistant declines new chat requests until the reset; nothing else in the CRM is affected.',
                        ['credits' => $freeCredits]
                    );
                }
            @endphp

            @if($billingActive)
                @include('partials.pricing-plans')
            @else
                @include('partials.pricing-legacy')
            @endif
        </div>
    </section>

    <section class="bg-gray-50 py-20 dark:bg-gray-950 md:py-28">
        <div class="mx-auto max-w-4xl px-6 lg:px-8">
            @if($billingActive)
                <div class="mx-auto mb-12 max-w-2xl text-center">
                    <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">{{ __('Choose how you get started') }}</h2>
                    <p class="mt-4 text-base leading-relaxed text-gray-500 dark:text-gray-400">{{ __('The same CRM at the core. A different level of help around it.') }}</p>
                </div>
                <div class="overflow-hidden rounded-2xl border border-gray-200/80 bg-white dark:border-white/[0.06] dark:bg-white/[0.02]" role="region" aria-label="{{ __('Compare Cloud Pro and Enterprise') }}">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ __('Compare Cloud Pro and Enterprise') }}</caption>
                        <thead class="hidden border-b border-gray-100 dark:border-white/[0.06] sm:table-header-group">
                            <tr>
                                <th scope="col" class="w-[30%] px-5 py-4 text-[11px] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">{{ __('What’s included') }}</th>
                                <th scope="col" class="w-[35%] bg-primary/[0.04] px-5 py-4 font-semibold text-primary-700 dark:bg-primary/[0.08] dark:text-primary-300">{{ __('billing.plans.cloud_pro') }}</th>
                                <th scope="col" class="w-[35%] px-5 py-4 font-semibold text-gray-950 dark:text-white">{{ __('billing.plans.enterprise') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/[0.06]">
                            @foreach([
                                [__('Users and records'), __('Unlimited'), __('Unlimited')],
                                [__('AI assistant'), __('2,000 credits each month'), __('Usage agreed with your team')],
                                [__('Getting started'), __('Self-service setup'), __('Scoped implementation project')],
                                [__('billing.comparison.integrations'), __('REST API and MCP server'), __('Custom integrations by agreement')],
                                [__('Hosting and updates'), __('Managed by Relaticle'), __('Agreed deployment and maintenance')],
                                [__('billing.comparison.support'), __('Email support'), __('Agreed support with the founding team')],
                            ] as [$feature, $pro, $enterprise])
                                <tr class="block px-5 py-4 sm:table-row sm:p-0">
                                    <th scope="row" class="block pb-2 text-left font-semibold text-gray-900 dark:text-white sm:table-cell sm:px-5 sm:py-4 sm:align-top sm:font-medium">{{ $feature }}</th>
                                    <td data-label="{{ __('billing.plans.cloud_pro') }}:" class="block py-0.5 text-gray-600 before:mr-1.5 before:font-medium before:text-primary-700 before:content-[attr(data-label)] dark:text-gray-400 dark:before:text-primary-300 sm:table-cell sm:bg-primary/[0.04] sm:px-5 sm:py-4 sm:align-top sm:before:content-none dark:sm:bg-primary/[0.08]">{{ $pro }}</td>
                                    <td data-label="{{ __('billing.plans.enterprise') }}:" class="block py-0.5 text-gray-600 before:mr-1.5 before:font-medium before:text-gray-900 before:content-[attr(data-label)] dark:text-gray-400 dark:before:text-white sm:table-cell sm:px-5 sm:py-4 sm:align-top sm:before:content-none">{{ $enterprise }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-center text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                    {{ __('AI credits depend on the model and tool calls.') }}
                    <a href="#pricing-faq" class="font-medium text-gray-700 underline underline-offset-4 hover:text-primary dark:text-gray-300 dark:hover:text-primary-300">{{ __('How credits work') }}</a>
                </p>
            @else
                <div class="mx-auto mb-12 max-w-2xl text-center">
                    <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">
                        {{ __('Self-hosted or hosted: how to choose') }}
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Both options run the identical open-source Relaticle codebase, with unlimited users and unlimited records on every plan. The real differences are who operates the server and how AI usage is metered.') }}
                    </p>
                </div>
                <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-200/80 bg-white dark:divide-white/[0.04] dark:border-white/[0.06] dark:bg-white/[0.02]">
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Price') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Self-Hosted') }}:</span> {{ __('Free forever') }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Hosted') }}:</span> {{ $hostedPriceCell }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Data ownership') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Self-Hosted') }}:</span> {{ __('Stays on your own infrastructure') }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Hosted') }}:</span> {{ __('Stored on Relaticle-managed infrastructure') }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Updates') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Self-Hosted') }}:</span> {{ __('You pull and deploy new Docker images yourself') }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Hosted') }}:</span> {{ $hostedUpdatesCell }}</p>
                    </li>
                    <li class="px-4 py-3 sm:px-6 sm:py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Getting help') }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Self-Hosted') }}:</span> {{ __('Community support on Discord') }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400"><span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Hosted') }}:</span> {{ __('Email support') }}</p>
                    </li>
                </ul>
            @endif
        </div>
    </section>

    <section id="pricing-faq" class="scroll-mt-24 bg-white py-20 dark:bg-gray-950 md:py-28">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <div class="mb-10 text-center">
                <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">
                    {{ __('Pricing questions, answered') }}
                </h2>
            </div>

            @php
                $trialFaq = [
                    __('What happens after my trial ends?'),
                    __(
                        'New workspaces start a :days-day trial automatically, with no card required. If a payment method isn\'t added before the trial ends, hosted access pauses and you\'re redirected to the billing page to subscribe. Self-hosting the exact same open-source codebase is always available as a fallback.',
                        ['days' => $trialDays]
                    ),
                ];

                $pricingFaqs = [
                    [
                        __('Is Relaticle really free to self-host?'),
                        __('Yes. Self-hosting is fully open source under the AGPL-3.0 license, with unlimited users and unlimited records and no credit card required. Deploy it yourself with the published Docker Compose file. Your data stays on your own server the entire time.'),
                    ],
                    [
                        __('Do you charge per seat?'),
                        __('No. Relaticle has never charged per seat. Every plan, self-hosted or hosted, is priced per workspace, so you can add as many teammates as you need without the bill changing.'),
                    ],
                    [__("What's included in the hosted plan?"), $hostedPlanAnswer],
                    ...($billingActive ? [$trialFaq] : []),
                    [__('What happens when I hit a plan limit?'), $planLimitAnswer],
                    [__('What counts as an AI credit?'), $creditFaqAnswer],
                    [__('Which AI models does my plan unlock?'), $modelsUnlockAnswer],
                    [__('Is there a message rate limit?'), $rateLimitAnswer],
                    [__('Are self-hosted installs exempt from AI credit limits?'), $selfHostedCreditAnswer],
                    [
                        __('Can I switch between self-hosted and cloud?'),
                        __('Yes. Both options run the identical open-source codebase against the same PostgreSQL schema, so neither locks you in. Companies, people, opportunities, tasks, and notes each have a built-in CSV export, and the import wizard on the other side accepts CSV. Moving between a self-hosted install and the hosted plan is a standard export and re-import, not a proprietary migration.'),
                    ],
                ];

                if ($billingActive) {
                    $pricingFaqs[] = [__('billing.enterprise.faq_title'), __('billing.enterprise.faq_body', ['price' => $enterprisePrice])];
                    $pricingFaqs[] = [__('billing.enterprise.timeline_title'), __('billing.enterprise.timeline_body')];
                }
            @endphp

            <x-marketing.faq-accordion :faqs="$pricingFaqs" id-prefix="pricing-faq" />
        </div>
    </section>

    <section id="pricing-cta" class="bg-gray-50 py-20 dark:bg-gray-950 md:py-28">
        <div class="mx-auto max-w-xl px-6 text-center lg:px-8">
            <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">
                {{ $billingActive ? __('Start with a 14-day trial') : __('Start for free today') }}
            </h2>
            <p class="mt-4 text-base leading-relaxed text-gray-500 dark:text-gray-400">
                {{ $billingActive
                    ? __('No card required. Unlimited users from day one, and the same open-source codebase to self-host whenever you want.')
                    : __('Unlimited users and records. Hosted by us, or on your own server.') }}
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <x-marketing.button :href="route('login')">
                    {{ __('Start for free') }}
                </x-marketing.button>
                @if($billingActive)
                    <x-marketing.button variant="secondary" :href="route('contact')">
                        {{ __('Talk to us') }}
                    </x-marketing.button>
                @else
                    <x-marketing.button variant="secondary" :href="route('selfHosted')">
                        {{ __('Explore self-hosting') }}
                    </x-marketing.button>
                @endif
            </div>
            <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ route('ai') }}" class="font-medium text-gray-700 underline underline-offset-4 hover:text-primary dark:text-gray-300 dark:hover:text-primary-300">{{ __('Explore the AI assistant and :count MCP tools', ['count' => $mcpToolCount]) }}</a>
                @if(! $billingActive)
                    <span class="mx-2" aria-hidden="true">·</span>
                    <a href="{{ route('contact') }}" class="font-medium text-gray-700 underline underline-offset-4 hover:text-primary dark:text-gray-300 dark:hover:text-primary-300">{{ __('Questions? Talk to us.') }}</a>
                @endif
            </p>
        </div>
    </section>

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
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Cloud Pro')
                            ->price('19')
                            ->priceCurrency('USD')
                            ->availability(\Spatie\SchemaOrg\ItemAvailability::InStock)
                            ->url(route('pricing'))
                            ->description('Per workspace, billed yearly at $228/year ($19/mo); $24/mo billed monthly.'),
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Enterprise')
                            ->priceSpecification(\Spatie\SchemaOrg\Schema::unitPriceSpecification()
                                ->minPrice(config('relaticle.enterprise.starting_price_yearly'))
                                ->priceCurrency('USD')
                                ->unitText('year'))
                            ->url(route('contact', ['plan' => 'enterprise']))
                            ->description(__('billing.enterprise.faq_body', ['price' => $enterprisePrice])),
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
</x-guest-layout>
