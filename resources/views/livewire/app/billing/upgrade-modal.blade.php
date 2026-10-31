@php
    $trialEndsAt = $workspace?->onGenericTrial() ? $workspace->trial_ends_at : null;
    $plans = collect([\App\Enums\Plan::Pro, \App\Enums\Plan::Scale])
        ->mapWithKeys(fn (\App\Enums\Plan $plan): array => [$plan->value => [
            'label' => $plan->marketingLabel(),
            'tagline' => __("billing.{$plan->value}_plan.tagline"),
            'rateYearly' => __('billing.upgrade.review.rate', ['price' => $plan->yearlyPricePerMonth()]),
            'rateMonthly' => __('billing.upgrade.review.rate', ['price' => $plan->monthlyPrice()]),
            'amountYearly' => __('billing.upgrade.review.amount', ['amount' => number_format($plan->yearlyPrice())]),
            'amountMonthly' => __('billing.upgrade.review.amount', ['amount' => number_format($plan->monthlyPrice())]),
            'save' => __('billing.yearly_save', ['percent' => $plan->yearlySavingPercent()]),
            'lineItem' => __('billing.upgrade.review.line_item', ['plan' => $plan->marketingLabel(), 'workspace' => $workspace?->name]),
            'credits' => __('billing.upgrade.review.credits', ['credits' => number_format($plan->credits())]),
        ]]);
    $default = $plans->get('pro');
@endphp

<div>
    @if($this->canUpgrade())
        <x-filament::modal
            :id="\App\Livewire\App\Billing\UpgradeModal::MODAL_ID"
            width="3xl"
            icon="ri-flashlight-line"
            :heading="__('billing.upgrade.heading')"
            :description="__('billing.per_workspace')"
        >
            <div
                x-data="{
                    plan: 'pro',
                    yearly: true,
                    plans: @js($plans),
                    get current() { return this.plans[this.plan] },
                    get amount() { return this.yearly ? this.current.amountYearly : this.current.amountMonthly },
                }"
                x-on:upgrade-plan-changed.window="plan = $event.detail.plan in plans ? $event.detail.plan : plan; yearly = $event.detail.interval !== 'monthly'"
                class="grid gap-6 md:grid-cols-2"
            >
                <div class="space-y-6">
                    <div>
                        <h3 id="upgrade-plan" class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                            <x-ri-stack-line class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                            {{ __('billing.upgrade.review.plan') }}
                        </h3>

                        <div class="mt-4 space-y-2.5" role="radiogroup" aria-labelledby="upgrade-plan">
                            @foreach($plans as $key => $option)
                                <label
                                    class="flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3.5 transition"
                                    :class="plan === @js($key)
                                        ? 'border-primary-500 bg-primary-50/60 ring-1 ring-primary-500 dark:border-primary-400 dark:bg-primary-400/10 dark:ring-primary-400'
                                        : 'border-gray-200 hover:border-gray-300 dark:border-white/10 dark:hover:border-white/20'"
                                >
                                    <input type="radio" name="upgrade-plan" class="mt-0.5 h-4 w-4 accent-primary-600" x-model="plan" value="{{ $key }}" @checked($key === 'pro')>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $option['label'] }}</span>
                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $option['tagline'] }}</span>
                                    </span>
                                    <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-white/[0.06] dark:text-gray-300"
                                        x-text="yearly ? @js($option['rateYearly']) : @js($option['rateMonthly'])">{{ $option['rateYearly'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <h3 id="upgrade-billing-period" class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                            <x-ri-calendar-line class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                            {{ __('billing.upgrade.review.billing_period') }}
                        </h3>

                        <div class="mt-4 space-y-2.5" role="radiogroup" aria-labelledby="upgrade-billing-period">
                            @foreach([true, false] as $isYearly)
                                <label
                                    class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3.5 transition"
                                    :class="yearly === @js($isYearly)
                                        ? 'border-primary-500 bg-primary-50/60 ring-1 ring-primary-500 dark:border-primary-400 dark:bg-primary-400/10 dark:ring-primary-400'
                                        : 'border-gray-200 hover:border-gray-300 dark:border-white/10 dark:hover:border-white/20'"
                                >
                                    <input type="radio" name="upgrade-interval" class="h-4 w-4 accent-primary-600" x-model.boolean="yearly" value="{{ $isYearly ? 'true' : 'false' }}" @checked($isYearly)>
                                    <span class="text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $isYearly ? __('billing.yearly') : __('billing.monthly') }}
                                    </span>
                                    @if($isYearly)
                                        <span class="rounded-full bg-primary/[0.1] px-1.5 py-0.5 text-[11px] font-semibold leading-none text-primary-700 dark:bg-primary/[0.25] dark:text-primary-300" x-text="current.save">{{ $default['save'] }}</span>
                                    @endif
                                    <span class="ms-auto rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-white/[0.06] dark:text-gray-300"
                                        x-text="{{ $isYearly ? 'current.rateYearly' : 'current.rateMonthly' }}">{{ $isYearly ? $default['rateYearly'] : $default['rateMonthly'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <aside class="rounded-xl bg-gray-50 p-5 dark:bg-white/[0.03]">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('billing.upgrade.review.summary') }}</h3>
                        <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-xs text-gray-600 dark:border-white/10 dark:bg-white/[0.04] dark:text-gray-300"
                            x-text="yearly ? @js(__('billing.upgrade.review.per_year')) : @js(__('billing.upgrade.review.per_month'))">{{ __('billing.upgrade.review.per_year') }}</span>
                    </div>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-950 dark:text-white" x-text="current.lineItem">{{ $default['lineItem'] }}</dt>
                            <dd class="text-gray-700 tabular-nums dark:text-gray-300" x-text="amount">{{ $default['amountYearly'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-950 dark:text-white" x-text="current.credits">{{ $default['credits'] }}</dt>
                            <dd class="text-gray-700 dark:text-gray-300">{{ __('billing.upgrade.review.credits_included') }}</dd>
                        </div>

                        <div class="border-t border-gray-200 dark:border-white/10"></div>

                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-600 dark:text-gray-400">{{ __('billing.upgrade.review.subtotal') }}</dt>
                            <dd class="text-gray-700 tabular-nums dark:text-gray-300" x-text="amount">{{ $default['amountYearly'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-600 dark:text-gray-400">{{ __('billing.upgrade.review.tax') }}</dt>
                            <dd class="text-gray-500 dark:text-gray-400">{{ __('billing.upgrade.review.tax_at_checkout') }}</dd>
                        </div>

                        <div class="border-t border-gray-200 dark:border-white/10"></div>

                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="font-medium text-gray-950 dark:text-white" x-text="yearly ? @js(__('billing.upgrade.review.total_yearly')) : @js(__('billing.upgrade.review.total_monthly'))">{{ __('billing.upgrade.review.total_yearly') }}</dt>
                            <dd class="font-display text-2xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white" x-text="amount">{{ $default['amountYearly'] }}</dd>
                        </div>
                    </dl>

                    @if($trialEndsAt !== null)
                        <p class="mt-4 rounded-lg bg-primary/[0.06] p-3 text-xs text-primary-700 dark:text-primary-300">
                            {{ __('billing.upgrade.trial_notice', ['date' => $trialEndsAt->toFormattedDateString()]) }}
                        </p>
                    @endif

                    <x-filament::button size="lg" class="mt-6 w-full justify-center"
                        wire:loading.attr="disabled" wire:target="checkout"
                        x-on:click="$wire.checkout(plan, yearly ? 'yearly' : 'monthly')">
                        {{ __('billing.upgrade.review.proceed') }}
                    </x-filament::button>

                    <p class="mt-3 flex items-center justify-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <x-ri-lock-line class="h-3.5 w-3.5" />
                        {{ __('billing.upgrade.review.secure') }}
                    </p>
                </aside>
            </div>
        </x-filament::modal>
    @endif
</div>
