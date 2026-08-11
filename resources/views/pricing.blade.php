<x-guest-layout
    title="Pricing - Relaticle"
    description="Relaticle pricing. No per-seat pricing — flat workspace plans. Unlimited users and records. Self-host free forever."
    ogTitle="Pricing - Relaticle"
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
                $proCredits = number_format(\App\Enums\Plan::Pro->credits());
                $trialDays = \App\Actions\Billing\StartProTrial::TRIAL_DAYS;

                if ($billingActive) {
                    $hostedPriceCell = __('$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)');
                    $hostedUpdatesCell = __('Managed by Relaticle — no self-hosted maintenance required');
                    $hostedPlanAnswer = __(
                        'Cloud Pro is :price and includes unlimited users and records, a :credits-credit monthly AI allowance across every supported model including premium ones, the REST API, the 30-tool MCP server, and email support. New workspaces start on a :days-day trial automatically, with no card required.',
                        [
                            'price' => '$19/mo per workspace ($228 billed yearly, or $24/mo billed monthly)',
                            'credits' => $proCredits,
                            'days' => $trialDays,
                        ]
                    );
                    $planLimitAnswer = __(
                        "CRM data itself is never capped — every plan supports unlimited users, companies, people, opportunities, tasks, and notes. The only metered resource is the AI assistant: Cloud Pro includes a :credits-credit monthly allowance that resets each billing (or trial) period. Once it's used up, the assistant stops answering new chat requests until the next reset; nothing else in the CRM is affected.",
                        ['credits' => $proCredits]
                    );
                } else {
                    $hostedPriceCell = __('$0/mo per workspace');
                    $hostedUpdatesCell = __('Zero-downtime updates and automatic daily backups, handled for you');
                    $hostedPlanAnswer = __('The hosted Cloud plan is $0/mo and includes unlimited users and data, the 30-tool MCP server, the REST API, all 22 custom field types, multi-team workspaces, zero-downtime updates, automatic daily backups, and email support — no credit card required.');
                    $planLimitAnswer = __("CRM data is never capped on any plan — every workspace supports unlimited users, companies, people, opportunities, tasks, and notes, whether you're self-hosting or on the hosted Cloud plan.");
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

                <div class="overflow-x-auto rounded-2xl border border-gray-200/80 dark:border-white/[0.06]">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 dark:bg-white/[0.02]">
                            <tr>
                                <th scope="col" class="px-4 py-3 sm:px-6 sm:py-4 font-medium text-gray-500 dark:text-gray-400">
                                    <span class="sr-only">{{ __('Category') }}</span>
                                </th>
                                <th scope="col" class="px-4 py-3 sm:px-6 sm:py-4 font-semibold text-gray-900 dark:text-white">{{ __('Self-Hosted') }}</th>
                                <th scope="col" class="px-4 py-3 sm:px-6 sm:py-4 font-semibold text-gray-900 dark:text-white">{{ __('Hosted') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/[0.04]">
                            <tr>
                                <th scope="row" class="px-4 py-3 sm:px-6 sm:py-4 font-medium text-gray-500 dark:text-gray-400">{{ __('Price') }}</th>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ __('Free forever') }}</td>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ $hostedPriceCell }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="px-4 py-3 sm:px-6 sm:py-4 font-medium text-gray-500 dark:text-gray-400">{{ __('Data ownership') }}</th>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ __('Stays on your own infrastructure') }}</td>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ __('Stored on Relaticle-managed infrastructure') }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="px-4 py-3 sm:px-6 sm:py-4 font-medium text-gray-500 dark:text-gray-400">{{ __('Updates') }}</th>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ __('You pull and deploy new Docker images yourself') }}</td>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ $hostedUpdatesCell }}</td>
                            </tr>
                            <tr>
                                <th scope="row" class="px-4 py-3 sm:px-6 sm:py-4 font-medium text-gray-500 dark:text-gray-400">{{ __('Getting help') }}</th>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ __('Community support on Discord') }}</td>
                                <td class="px-4 py-3 sm:px-6 sm:py-4 text-gray-700 dark:text-gray-300">{{ __('Email support') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- FAQ --}}
            <div class="mt-16 max-w-3xl mx-auto">
                <div class="mx-auto mb-10 max-w-2xl text-center">
                    <h2 class="font-display text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white sm:text-3xl">
                        {{ __('Pricing questions, answered') }}
                    </h2>
                </div>

                <div class="space-y-8">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Is Relaticle really free to self-host?') }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            {{ __('Yes. Self-hosting is fully open source under the AGPL-3.0 license, with unlimited users and unlimited records and no credit card required. Deploy it yourself with the published Docker Compose file — your data stays on your own server the entire time.') }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Do you charge per seat?') }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            {{ __('No — Relaticle has never charged per seat. Every plan, self-hosted or hosted, is priced per workspace, so you can add as many teammates as you need without the bill changing.') }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __("What's included in the hosted plan?") }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            {{ $hostedPlanAnswer }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('What happens when I hit a plan limit?') }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            {{ $planLimitAnswer }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('What counts as an AI credit?') }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            {{ __('One credit is used each time the built-in AI assistant sends a chat reply or generates a record summary. Using the REST API, the MCP server, or the CRM directly never touches your credit balance.') }}
                        </p>
                    </div>

                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Can I switch between self-hosted and cloud?') }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            {{ __('Yes. Both options run the identical open-source codebase against the same PostgreSQL schema, so neither locks you in. Companies, people, opportunities, tasks, and notes each have a built-in CSV export, and the import wizard on the other side accepts CSV — moving between a self-hosted install and the hosted plan is a standard export and re-import, not a proprietary migration.') }}
                        </p>
                    </div>

                    @if($billingActive)
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('What happens after my trial ends?') }}</h3>
                            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                                {{ __(
                                    'New workspaces start a :days-day trial automatically, with no card required. If a payment method isn\'t added before the trial ends, hosted access pauses and you\'re redirected to the billing page to subscribe. Self-hosting the exact same open-source codebase is always available as a fallback.',
                                    ['days' => $trialDays]
                                ) }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Trust signals --}}
            <div class="mt-16 max-w-4xl mx-auto">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach([
                        ['ri-shield-check-line', '2,000+', 'Automated Tests'],
                        ['ri-robot-2-line', '30', 'MCP Tools'],
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
                ->offers($billingActive
                    ? [
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Self-hosted')
                            ->price('0')
                            ->priceCurrency('USD')
                            ->url(route('pricing'))
                            ->description('Free forever, AGPL-3.0 open source, unlimited users and records.'),
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Cloud Pro')
                            ->price('19')
                            ->priceCurrency('USD')
                            ->url(route('pricing'))
                            ->description('Per workspace, billed yearly at $228/year ($19/mo); $24/mo billed monthly.'),
                    ]
                    : [
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Self-hosted')
                            ->price('0')
                            ->priceCurrency('USD')
                            ->url(route('pricing'))
                            ->description('Free forever, AGPL-3.0 open source, unlimited users and records.'),
                        \Spatie\SchemaOrg\Schema::offer()
                            ->name('Cloud')
                            ->price('0')
                            ->priceCurrency('USD')
                            ->url(route('pricing'))
                            ->description('Free hosted plan, managed by Relaticle.'),
                    ]
                )
            );
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>
