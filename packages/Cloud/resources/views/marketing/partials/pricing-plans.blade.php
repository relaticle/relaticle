@use(Illuminate\Support\Number)
@use(App\Enums\Plan)
@use(App\Models\Workspace)
@use(Relaticle\Cloud\Marketing\Enums\ContactInquiry)
@use(Relaticle\Cloud\Marketing\Support\LocalizedUrl)
@php
    $priceLabel = 'type-label text-gray-500 dark:text-gray-400';
    $price = 'text-5xl font-semibold tracking-[-0.03em] text-gray-950 tabular-nums dark:text-white';
    $muted = 'text-sm text-gray-500 dark:text-gray-400';

    $cards = [
        [
            'plan' => Plan::Pro,
            'label' => Plan::Pro->marketingLabel(),
            'tagline' => __('cloud::billing.pro_plan.pricing_tagline'),
            'includes' => __('Your workspace includes'),
            'features' => __('cloud::billing.pro_plan.features'),
            'note' => __('No card needed.'),
        ],
        [
            'plan' => Plan::Scale,
            'label' => Plan::Scale->marketingLabel(),
            'tagline' => __('cloud::billing.scale_plan.pricing_tagline'),
            'includes' => __('cloud::billing.scale_plan.includes'),
            'features' => __('cloud::billing.scale_plan.features'),
            'note' => __('Trial runs on Pro. Pick Scale anytime.'),
        ],
        [
            'plan' => Plan::Enterprise,
            'label' => __('billing.plans.enterprise'),
            'tagline' => __('cloud::billing.enterprise.pricing_tagline'),
            'includes' => __('cloud::billing.enterprise.includes'),
            'features' => __('cloud::billing.enterprise.features'),
            'note' => __('No commitment until scope is agreed.'),
        ],
    ];
@endphp

<div class="px-3 pb-3 sm:px-6 sm:pb-6">
    <x-cloud::marketing.stage light="seam" dots class="bg-tint rounded-3xl">
        <div id="pricing-plans" class="grid gap-3 p-3 sm:gap-4 sm:p-6 lg:grid-cols-3 lg:grid-rows-[repeat(4,auto)_1fr] lg:gap-x-5 lg:gap-y-0 lg:p-8">
            @foreach($cards as $card)
                @php
                    $plan = $card['plan'];
                    $isScale = $plan === Plan::Scale;
                    $isEnterprise = $plan === Plan::Enterprise;
                @endphp
                <section id="{{ $plan->value }}-plan" @class([
                    'bg-surface relative flex flex-col rounded-2xl px-6 py-7 lg:row-span-5 lg:grid lg:grid-rows-subgrid lg:gap-0 lg:px-8 lg:py-8',
                    'ring-primary ring-2 dark:ring-primary-400' => $isScale,
                    'ring-1 ring-gray-950/5 dark:ring-white/10' => ! $isScale,
                ])>
                    <div>
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-lg font-medium text-gray-950 dark:text-white">{{ $card['label'] }}</h2>
                            @if($isScale)
                                <span class="type-label rounded-full bg-primary-50 px-2 py-1 text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ __('cloud::billing.scale_plan.badge') }}</span>
                            @endif
                        </div>
                        <p class="{{ $muted }} mt-1">{{ $card['tagline'] }}</p>
                    </div>

                    @if($isEnterprise)
                        <div class="pt-7" aria-label="{{ ContactInquiry::Enterprise->price() }}">
                            <p class="{{ $priceLabel }}">{{ __('Starting at') }}</p>
                            <p class="mt-2 flex flex-wrap items-baseline gap-x-1.5">
                                <span class="{{ $price }}">{{ '$'.Number::abbreviate(config('relaticle.enterprise.starting_price_yearly'), maxPrecision: 1) }}</span>
                                <span class="{{ $muted }}">{{ __('/ year') }}</span>
                            </p>
                            <p class="{{ $muted }} mt-2">{{ __('cloud::billing.enterprise.billing') }}</p>
                        </div>
                    @else
                        <div class="pt-7">
                            <p class="{{ $priceLabel }}">{{ __('Per workspace') }}</p>
                            <p class="mt-2 flex flex-wrap items-baseline gap-x-1.5 gap-y-2">
                                <span class="{{ $price }}" x-text="yearly ? @js('$'.$plan->yearlyPricePerMonth()) : @js('$'.$plan->monthlyPrice())">${{ $plan->yearlyPricePerMonth() }}</span>
                                <span class="{{ $muted }}">{{ __('cloud::billing.per_month') }}</span>
                                <span x-show="yearly" class="ms-1 rounded-full bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/[0.08] dark:text-gray-200">{{ __('cloud::billing.yearly_save', ['percent' => $plan->yearlySavingPercent()]) }}</span>
                            </p>
                            <p class="{{ $muted }} mt-2" x-text="yearly ? @js(__('cloud::billing.billed_yearly', ['price' => Number::format($plan->yearlyPrice())])) : @js(__('cloud::billing.billed_monthly'))">{{ __('cloud::billing.billed_yearly', ['price' => Number::format($plan->yearlyPrice())]) }}</p>
                            <p class="{{ $muted }} mt-0.5">{{ __('Unlimited users at this price') }}</p>
                        </div>
                    @endif

                    <div class="pt-7">
                        @if($isEnterprise)
                            <x-cloud::marketing.button variant="secondary" :href="LocalizedUrl::route('contact', ['plan' => 'enterprise'])" class="w-full">
                                {{ __('cloud::billing.enterprise.contact') }}
                            </x-cloud::marketing.button>
                        @else
                            <x-cloud::marketing.button :variant="$isScale ? 'primary' : 'secondary'" :href="route('login')" class="w-full" data-analytics-intent="signup" data-analytics-placement="pricing">
                                {{ __('Start :days-day trial', ['days' => Workspace::PRO_TRIAL_DAYS]) }}
                            </x-cloud::marketing.button>
                        @endif
                        <p class="{{ $muted }} mt-3 text-center">{{ $card['note'] }}</p>
                    </div>

                    <div class="border-rail mt-7 border-t border-dashed pt-7">
                        <p class="{{ $priceLabel }} flex items-center gap-1.5">
                            <x-lucide-sparkles class="size-3.5 text-primary dark:text-primary-400" />
                            {{ __('cloud::billing.allowance.label') }}
                        </p>
                        @if($isEnterprise)
                            <p class="mt-2 text-2xl font-semibold tracking-[-0.02em] text-gray-950 dark:text-white">{{ __('cloud::billing.allowance.custom') }}</p>
                            <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">{{ __('cloud::billing.allowance.agreed') }}</p>
                        @else
                            <p class="mt-2 flex items-baseline gap-x-1.5">
                                <span class="text-2xl font-semibold tracking-[-0.02em] text-gray-950 tabular-nums dark:text-white">{{ Number::format($plan->credits()) }}</span>
                                <span class="{{ $muted }}">{{ __('cloud::billing.allowance.credits') }}</span>
                            </p>
                            <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">{{ __('cloud::billing.allowance.replies', ['replies' => Number::format($plan->typicalReplies())]) }}</p>
                            <p class="{{ $muted }} mt-2">{{ __('Need more? Buy extra credits anytime. They never expire.') }}</p>
                        @endif
                    </div>

                    <div class="flex flex-col pt-7">
                        <p class="{{ $priceLabel }} mb-4">{{ $card['includes'] }}</p>
                        <ul class="space-y-3 text-sm leading-5 text-gray-700 dark:text-gray-300">
                            @foreach($card['features'] as $feature)
                                <li class="flex items-start gap-2.5">
                                    <x-lucide-check class="mt-0.5 size-4 shrink-0 text-gray-950 dark:text-white" />
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                        @if($isEnterprise)
                            <p class="{{ $muted }} mt-auto pt-6">{{ __('cloud::billing.enterprise.terms') }}</p>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        <div class="px-3 pb-3 sm:px-6 sm:pb-6 lg:px-8 lg:pb-8">
            <div id="self-hosting" class="bg-surface flex flex-col gap-5 rounded-2xl px-6 py-6 ring-1 ring-gray-950/5 sm:flex-row sm:items-center sm:justify-between lg:px-8 dark:ring-white/10">
                <div class="flex items-start gap-5">
                    <p class="text-3xl font-semibold tracking-[-0.03em] text-gray-950 tabular-nums dark:text-white">$0</p>
                    <div>
                        <h2 class="text-base font-medium text-gray-950 dark:text-white">{{ __('Prefer to self-host? It’s free.') }}</h2>
                        <p class="{{ $muted }} mt-0.5">{{ __('AGPL-3.0 open source. Unlimited users and records on your own server, forever.') }}</p>
                    </div>
                </div>
                <x-cloud::marketing.button variant="secondary" size="sm" :href="LocalizedUrl::route('selfHosted')" iconTrailing="lucide-arrow-right" class="shrink-0 self-start sm:self-center">
                    {{ __('Explore self-hosting') }}
                </x-cloud::marketing.button>
            </div>
        </div>
    </x-cloud::marketing.stage>
</div>
