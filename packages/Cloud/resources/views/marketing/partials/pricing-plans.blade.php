@php
    $planCell = 'bg-surface relative flex flex-col';
    $priceLabel = 'type-label text-gray-500 dark:text-gray-400';
    $price = 'text-5xl font-semibold tracking-[-0.03em] text-gray-950 tabular-nums dark:text-white';
    $featureList = 'space-y-3 text-sm leading-5 text-gray-700 dark:text-gray-300';
@endphp

<div x-data="{ yearly: true }">
    <div class="border-rail flex flex-col gap-4 border-t px-6 py-5 sm:flex-row sm:items-center sm:justify-between md:px-10">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Every price is per workspace, with unlimited users and records.') }}</p>
        <x-cloud::billing.interval-toggle class="sm:items-end" />
    </div>

    <div id="pricing-plans" class="border-rail bg-rail grid gap-px border-t lg:grid-cols-3">
        @foreach([\App\Enums\Plan::Pro, \App\Enums\Plan::Scale] as $plan)
            @php
                $isScale = $plan === \App\Enums\Plan::Scale;
            @endphp
            <section id="{{ $plan->value }}-plan" class="{{ $planCell }}">
                @if($isScale)
                    <span class="bg-primary absolute inset-x-0 top-0 h-0.5 dark:bg-primary-400" aria-hidden="true"></span>
                @endif

                <div class="p-6 md:p-10">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-medium text-gray-950 dark:text-white">{{ $plan->marketingLabel() }}</h2>
                        @if($isScale)
                            <span class="type-label rounded-full bg-primary-50 px-2 py-1 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ __('cloud::billing.scale_plan.badge') }}</span>
                        @endif
                    </div>
                    <p class="mt-1.5 text-base text-gray-500 dark:text-gray-400">{{ __("cloud::billing.{$plan->value}_plan.tagline") }}</p>

                    <div class="mt-8">
                        <p class="{{ $priceLabel }}">{{ __('Per workspace') }}</p>
                        <p class="mt-3 flex flex-wrap items-baseline gap-x-1.5 gap-y-2">
                            <span class="{{ $price }}" x-text="yearly ? @js('$'.$plan->yearlyPricePerMonth()) : @js('$'.$plan->monthlyPrice())">${{ $plan->yearlyPricePerMonth() }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.per_month') }}</span>
                            <span x-show="yearly" class="ms-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/[0.08] dark:text-gray-200">{{ __('cloud::billing.yearly_save', ['percent' => $plan->yearlySavingPercent()]) }}</span>
                        </p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" x-text="yearly ? @js(__('cloud::billing.billed_yearly', ['price' => number_format($plan->yearlyPrice())])) : @js(__('cloud::billing.billed_monthly'))">{{ __('cloud::billing.billed_yearly', ['price' => number_format($plan->yearlyPrice())]) }}</p>
                    </div>

                    <x-cloud::marketing.button :variant="$isScale ? 'primary' : 'secondary'" :href="route('login')" class="mt-8 w-full" data-analytics-intent="signup" data-analytics-placement="pricing">
                        {{ __('Start for free') }}
                    </x-cloud::marketing.button>
                    <p class="mt-3 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('14-day trial. No card required.') }}</p>
                </div>

                <div class="border-rail flex flex-1 flex-col border-t p-6 md:px-10">
                    <p class="{{ $priceLabel }} mb-5">{{ $isScale ? __('cloud::billing.scale_plan.includes') : __('Your workspace includes') }}</p>
                    <ul class="{{ $featureList }}">
                        @foreach(__("cloud::billing.{$plan->value}_plan.features") as $feature)
                            <li class="flex items-start gap-3">
                                <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-gray-950 dark:text-white" />
                                {{ $feature }}
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-auto pt-6 text-sm text-gray-500 dark:text-gray-400">{{ __('Top up AI credits anytime.') }}</p>
                </div>
            </section>
        @endforeach

        <section id="enterprise-plan" class="{{ $planCell }}">
            <div class="p-6 md:p-10">
                <h2 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('billing.plans.enterprise') }}</h2>
                <p class="mt-1.5 text-base text-gray-500 dark:text-gray-400">{{ __('cloud::billing.enterprise.tagline') }}</p>

                <div class="mt-8" aria-label="{{ __('cloud::billing.enterprise.starting_price', ['price' => $enterprisePrice]) }}">
                    <p class="{{ $priceLabel }}">{{ __('Starting at') }}</p>
                    <p class="mt-3 flex flex-wrap items-baseline gap-x-1.5">
                        <span class="{{ $price }}">${{ $enterprisePrice }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('/ year') }}</span>
                    </p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.enterprise.billing') }}</p>
                </div>

                <x-cloud::marketing.button variant="secondary" :href="route('contact', ['plan' => 'enterprise'])" class="mt-8 w-full">
                    {{ __('cloud::billing.enterprise.contact') }}
                </x-cloud::marketing.button>
                <p class="mt-3 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No commitment until scope is agreed.') }}</p>
            </div>

            <div class="border-rail flex flex-1 flex-col border-t p-6 md:px-10">
                <p class="{{ $priceLabel }} mb-5">{{ __('cloud::billing.enterprise.includes') }}</p>
                <ul class="{{ $featureList }}">
                    @foreach(__('cloud::billing.enterprise.features') as $feature)
                        <li class="flex items-start gap-3">
                            <x-ri-check-line class="mt-0.5 size-4 shrink-0 text-gray-950 dark:text-white" />
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-auto pt-6 text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.enterprise.terms') }}</p>
            </div>
        </section>
    </div>
</div>

<div id="self-hosting" class="border-rail flex flex-col gap-5 border-t p-6 sm:flex-row sm:items-center sm:justify-between md:px-10 md:py-8">
    <div>
        <h2 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Prefer to self-host? It’s free.') }}</h2>
        <p class="type-prose mt-1.5 text-gray-500 dark:text-gray-400">{{ __('AGPL-3.0 open source. Unlimited users and records on your own server, forever.') }}</p>
    </div>
    <x-cloud::marketing.button variant="secondary" size="sm" :href="route('selfHosted')" iconTrailing="ri-arrow-right-line" class="shrink-0 self-start sm:self-center">
        {{ __('Explore self-hosting') }}
    </x-cloud::marketing.button>
</div>
