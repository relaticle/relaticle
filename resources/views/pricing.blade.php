<x-guest-layout
    title="Pricing - $19/mo flat, unlimited users - Relaticle"
    description="No per-seat pricing. One flat workspace plan at $19/mo billed yearly, with unlimited users and records. 14-day trial, no card. Self-host free forever."
    ogTitle="Pricing - $19/mo flat, unlimited users - Relaticle"
>
    <section class="relative pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-5xl mx-auto px-6 lg:px-8">

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-heart-pulse-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">Simple pricing</span>
                </div>
            </div>

            {{-- Header --}}
            <div class="text-center max-w-2xl mx-auto mb-16 md:mb-20">
                <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                    No per-seat pricing. Ever.
                </h1>
                <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed">
                    Unlimited users. Unlimited data. Self-host for free forever, or let us run it for you.
                </p>
            </div>

            @php
                $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
                $freeCredits = number_format(\App\Enums\Plan::Free->credits());
                $proCredits = number_format(\App\Enums\Plan::Pro->credits());
                $enterpriseCredits = number_format(\App\Enums\Plan::Enterprise->credits());
                $freeRateLimit = \App\Enums\Plan::Free->rateLimit();
                $proRateLimit = \App\Enums\Plan::Pro->rateLimit();
                $trialDays = \App\Actions\Billing\StartProTrial::TRIAL_DAYS;

                // Sourced from packages/Chat/config/chat.php's model catalog ('credit_multiplier'
                // per model) and 'tool_call_credit_bonus'. Settlement formula verified in
                // packages/Chat/src/Services/CreditService.php::calculateCredits():
                // max(1, ceil(multiplier + toolCalls * toolBonus)).
                $opusReplies = intdiv((int) \App\Enums\Plan::Pro->credits(), 3);

                // Filtered from config rather than hardcoded so this list can never name a model
                // the app won't actually serve. Gemini 3 Flash and Gemini 3.1 Pro carry
                // 'supports_tools' => false in chat.php, which makes ModelDescriptor::isAvailable()
                // (packages/Chat/src/Support/ModelDescriptor.php:49) return false unconditionally —
                // AiModelResolver::pick() can never select them, so they must not appear here even
                // though they're in the catalog with real min_plan/credit_multiplier values.
                $toolCapableCloudModels = collect(config('chat.models', []))
                    ->filter(fn (array $model): bool => ($model['supports_tools'] ?? false) === true && ($model['self_hosted'] ?? false) === false);
                $freeCloudModels = $toolCapableCloudModels->where('min_plan', 'free')->pluck('label')->join(', ', ' and ');
                $paidCloudModels = $toolCapableCloudModels->where('min_plan', 'pro')->pluck('label')->join(', ', ' and ');
                $multiplierOneModels = $toolCapableCloudModels->where('credit_multiplier', 1.0)->pluck('label')->join(', ', ' and ');
                $multiplierOneHalfModels = $toolCapableCloudModels->where('credit_multiplier', 1.5)->pluck('label')->join(', ', ' and ');
                $multiplierThreeModels = $toolCapableCloudModels->where('credit_multiplier', 3.0)->pluck('label')->join(', ', ' and ');

                $creditFaqAnswer = __(
                    'Credit cost depends on the model and how much work a reply does — it is not flat. Each message costs its model\'s credit multiplier (1x for :oneX and self-hosted models; 1.5x for :oneFiveX; 3x for :threeX), plus 0.5 credits for every tool call the assistant makes while answering — searching, creating, or updating a record — rounded up to the next whole credit with a 1-credit minimum. A simple Sonnet 4.6 reply with no tool calls costs 1 credit; an Opus 4.7 reply that touches two records costs 4. Using the REST API or the MCP server directly, outside the built-in chat, never touches your credit balance.',
                    ['oneX' => $multiplierOneModels, 'oneFiveX' => $multiplierOneHalfModels, 'threeX' => $multiplierThreeModels]
                );

                // "Cloud Pro" is the billing-on marketing name only — under billing-off there is
                // no self-service path onto a paid plan at all (CreateTeam only auto-starts a
                // trial when Feature::active(Billing), and the billing page 403s otherwise), so
                // these two facts use the generic "a paid plan" label when billing is off.
                $paidPlanLabel = $billingActive ? __('Cloud Pro') : __('a paid plan');

                // No Enterprise plan card or checkout path exists anywhere in the codebase, so
                // whether it's an actual purchasable offering isn't something the code can confirm
                // — it's mentioned nowhere in this page's visible copy for that reason.
                $modelsUnlockAnswer = __(
                    'Every plan can use :freeModels and any self-hosted model you connect yourself. :paidPlan additionally unlocks :paidModels — the models with a higher credit multiplier.',
                    ['freeModels' => $freeCloudModels, 'paidModels' => $paidCloudModels, 'paidPlan' => $paidPlanLabel]
                );

                $rateLimitAnswer = __(
                    'Yes — a per-minute cap shared across the whole workspace, not per person: :free messages/minute on Free, :pro/minute on :paidPlan. It exists to stop runaway usage, not to constrain normal work.',
                    ['free' => $freeRateLimit, 'pro' => $proRateLimit, 'paidPlan' => $paidPlanLabel]
                );

                $selfHostedCreditAnswer = __(
                    'No. Self-hosting does not disable credit metering: every workspace, self-hosted or hosted, defaults to the Free plan\'s :credits-credit monthly allowance — the same one a brand-new Cloud signup gets. Self-hosters do have direct database access, so they can raise their own workspace\'s plan value, but no plan removes metering entirely (even the highest built-in plan caps out at :enterpriseCredits credits/month), and changing the plan value alone doesn\'t reset the current period\'s balance — that only happens automatically once the existing period ends.',
                    ['credits' => $freeCredits, 'enterpriseCredits' => $enterpriseCredits]
                );

                if ($billingActive) {
                    $hostedPriceCell = __('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)');
                    $hostedUpdatesCell = __('Managed by Relaticle — no self-hosted maintenance required');
                    $hostedPlanAnswer = __(
                        'Cloud Pro is :price and includes unlimited users and records, every supported AI model from Sonnet 4.6 up to Opus 4.7, the REST API, the 32-tool MCP server, and email support. Each workspace gets a :credits-credit monthly AI allowance; how far it goes depends on the model and how many tool calls each reply makes (see "What counts as an AI credit?" below) — as a reference point, :credits credits covers roughly :credits simple Sonnet 4.6 replies, or around :opusReplies Opus 4.7 replies before tool calls. New workspaces start on a :days-day trial automatically, with no card required.',
                        [
                            'price' => '$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)',
                            'credits' => $proCredits,
                            'opusReplies' => number_format($opusReplies),
                            'days' => $trialDays,
                        ]
                    );
                    $planLimitAnswer = __(
                        'CRM data itself is never capped — every plan supports unlimited users, companies, people, opportunities, tasks, and notes. The only metered resource is the AI assistant: Cloud Pro\'s :credits credits a month reset each billing (or trial) period. Once they are used up, the assistant declines new chat requests until the next reset — Cloud Pro workspaces can also buy a prepaid credit top-up instead of waiting. Nothing else in the CRM is affected.',
                        ['credits' => $proCredits]
                    );
                } else {
                    $hostedPriceCell = __('$0/mo per workspace');
                    $hostedUpdatesCell = __('Zero-downtime updates and automatic daily backups, handled for you');
                    $hostedPlanAnswer = __('The hosted Cloud plan is $0/mo and includes unlimited users and data, the 32-tool MCP server, the REST API, all 22 custom field types, multi-team workspaces, zero-downtime updates, automatic daily backups, and email support — no credit card required.');
                    $planLimitAnswer = __(
                        'CRM data itself is never capped on any plan — every workspace supports unlimited users, companies, people, opportunities, tasks, and notes, whether you\'re self-hosting or on the hosted Cloud plan. The AI assistant is metered, though: every workspace defaults to the Free plan\'s :credits credits a month (self-hosted included — see "Are self-hosted installs exempt from AI credit limits?" below), resetting every calendar month. Once they are used up, the assistant declines new chat requests until the reset; nothing else in the CRM is affected.',
                        ['credits' => $freeCredits]
                    );
                }
            @endphp

            @if($billingActive)
                @include('partials.pricing-plans')
            @else
                @include('partials.pricing-legacy')
            @endif

            {{-- Self-hosted vs hosted comparison --}}
            <div class="mt-16 max-w-4xl mx-auto">
                <div class="mx-auto mb-10 max-w-2xl text-center">
                    <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">
                        {{ __('Self-hosted or hosted: how to choose') }}
                    </h2>
                    <p class="mt-4 text-base leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Both options run the identical open-source Relaticle codebase, with unlimited users and unlimited records on every plan. The real differences are who operates the server and how AI usage is metered.') }}
                    </p>
                </div>

                {{--
                    List layout kept for responsive styling; tables DO convert to
                    markdown since the TableAwareLeagueDriver landed. <ul>/<li>/<p>
                    (category names are CSS-bold, not <strong> — no semantic-bold
                    tag is used) were verified to survive conversion — each row
                    below reads as "Category" / "Self-Hosted: X" / "Hosted: Y" in markdown.
                --}}
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
            </div>

            {{-- FAQ --}}
            <div class="mt-16 max-w-3xl mx-auto">
                <div class="mx-auto mb-6 max-w-2xl text-center">
                    <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">
                        {{ __('Pricing questions, answered') }}
                    </h2>
                </div>

                @php
                    $pricingFaqs = [
                        [
                            __('Is Relaticle really free to self-host?'),
                            __('Yes. Self-hosting is fully open source under the AGPL-3.0 license, with unlimited users and unlimited records and no credit card required. Deploy it yourself with the published Docker Compose file — your data stays on your own server the entire time.'),
                        ],
                        [
                            __('Do you charge per seat?'),
                            __('No — Relaticle has never charged per seat. Every plan, self-hosted or hosted, is priced per workspace, so you can add as many teammates as you need without the bill changing.'),
                        ],
                        [__("What's included in the hosted plan?"), $hostedPlanAnswer],
                        [__('What happens when I hit a plan limit?'), $planLimitAnswer],
                        [__('What counts as an AI credit?'), $creditFaqAnswer],
                        [__('Which AI models does my plan unlock?'), $modelsUnlockAnswer],
                        [__('Is there a message rate limit?'), $rateLimitAnswer],
                        [__('Are self-hosted installs exempt from AI credit limits?'), $selfHostedCreditAnswer],
                        [
                            __('Can I switch between self-hosted and cloud?'),
                            __('Yes. Both options run the identical open-source codebase against the same PostgreSQL schema, so neither locks you in. Companies, people, opportunities, tasks, and notes each have a built-in CSV export, and the import wizard on the other side accepts CSV — moving between a self-hosted install and the hosted plan is a standard export and re-import, not a proprietary migration.'),
                        ],
                    ];

                    if ($billingActive) {
                        $pricingFaqs[] = [
                            __('What happens after my trial ends?'),
                            __(
                                'New workspaces start a :days-day trial automatically, with no card required. If a payment method isn\'t added before the trial ends, hosted access pauses and you\'re redirected to the billing page to subscribe. Self-hosting the exact same open-source codebase is always available as a fallback.',
                                ['days' => $trialDays]
                            ),
                        ];
                    }
                @endphp

                <x-marketing.faq-accordion :faqs="$pricingFaqs" id-prefix="pricing-faq" />
            </div>

            {{-- Trust signals --}}
            <div class="mt-16 max-w-4xl mx-auto">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach([
                        ['ri-shield-check-line', '2,000+', 'Automated Tests'],
                        ['ri-robot-2-line', '32', 'MCP Tools'],
                        ['ri-stack-line', '22', 'Field Types'],
                        ['ri-lock-line', '5-Layer', 'Authorization'],
                    ] as [$icon, $value, $label])
                        <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] px-5 py-4 text-center">
                            <x-dynamic-component :component="$icon" class="w-5 h-5 text-primary dark:text-primary-400 mx-auto mb-2"/>
                            <div class="text-lg font-semibold text-gray-900 dark:text-white tracking-tight">{{ $value }}</div>
                            <div class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5 uppercase tracking-wider font-medium">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Two of the tiles above are the whole subject of a page each. --}}
                <p class="mt-4 text-center text-xs text-gray-500 dark:text-gray-400">
                    <a href="{{ route('ai') }}" class="underline decoration-gray-300 dark:decoration-gray-600 underline-offset-2 hover:text-primary dark:hover:text-primary-400">{{ __('What the AI assistant and MCP server do') }}</a>
                    <span class="px-1.5 text-gray-300 dark:text-gray-600" aria-hidden="true">&middot;</span>
                    <a href="{{ route('selfHosted') }}" class="underline decoration-gray-300 dark:decoration-gray-600 underline-offset-2 hover:text-primary dark:hover:text-primary-400">{{ __('Run it free on your own server') }}</a>
                </p>
            </div>

            {{-- Help CTA --}}
            <div class="mt-8 max-w-4xl mx-auto">
                <div class="relative rounded-2xl border border-gray-200/80 dark:border-white/[0.06] bg-gray-50/50 dark:bg-white/[0.015] p-8 flex flex-col sm:flex-row items-center gap-6 overflow-hidden">
                    <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-primary/[0.04] dark:bg-primary/[0.08] rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>
                    <div class="relative flex-1 text-left">
                        <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">Need help choosing?</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            Not sure which option fits? Have questions about deployment or migration? We're happy to help.
                        </p>
                    </div>
                    <x-marketing.button variant="secondary" href="{{ route('contact') }}" class="relative shrink-0">
                        Get in touch
                    </x-marketing.button>
                </div>
            </div>

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
