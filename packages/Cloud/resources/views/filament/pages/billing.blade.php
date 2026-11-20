@use(Illuminate\Support\Number)
@use(Relaticle\Cloud\Marketing\Enums\ContactInquiry)
@use(Relaticle\Cloud\Marketing\Support\LocalizedUrl)
<x-filament-panels::page>
    @php
        $purchased = (int) ($balance?->purchased_credits ?? 0);
        $used = (int) ($balance?->credits_used ?? 0);
        $monthlyUsed = min($used, $allowance);
        $remaining = (int) ($balance?->credits_remaining ?? 0);
        $isEnterprise = $workspace->plan === \App\Enums\Plan::Enterprise;
        $isManaged = $workspace->plan->isManaged();
        $usedPercent = $allowance > 0 ? min(100, (int) round($used / $allowance * 100)) : 0;

        $isSubscribed = $subscription?->valid() ?? false;
        $canManageSubscription = $subscription && ($subscription->valid() || $pastDue);
        $onTrial = ! $isManaged && $workspace->onGenericTrial();
        $onLegacyFree = $isGrandfathered
            && $workspace->plan === \App\Enums\Plan::Free
            && ! $onTrial
            && ! $isSubscribed;
        $showsManagedCard = $isManaged || (! $subscription
            && ! $onTrial
            && ! $onLegacyFree
            && $workspace->plan !== \App\Enums\Plan::Free);
        $showEnterpriseOffer = $canManageBilling && ! $isEnterprise && ! $pastDue && ! $onGrace && ! $activating;
        $showsPlanAction = $canManageBilling && $canManageSubscription && ! $pastDue && ! $showsManagedCard;

        $planLabel = $summary->plan;
        $statusClasses = match ($summary->tone) {
            'danger' => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
            'warning' => 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400',
            'primary' => 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400',
            'success' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
            default => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
        };

        $meterColor = match (true) {
            $usedPercent >= 100 => 'bg-danger-500',
            $usedPercent >= 80 => 'bg-warning-500',
            default => 'bg-primary',
        };

        $card = 'rounded-2xl border border-[var(--surface-card-border)] bg-white shadow-sm dark:bg-[var(--surface-card-bg)] dark:shadow-none';
    @endphp

    <div class="w-full max-w-2xl space-y-5">
        @if($activating)
            <x-cloud::billing.activating :card="$card" />
        @endif

        @if($creditsFulfilling)
            <x-cloud::billing.activating
                :card="$card"
                :title="__('cloud::billing.packs.fulfilling_title')"
                :delayed-title="__('cloud::billing.packs.fulfilling_delayed_title')"
                :delayed-body="__('cloud::billing.packs.fulfilling_delayed_body')"
            />
        @endif

        <section class="{{ $card }} overflow-hidden" aria-label="{{ __('billing.title') }}">
            <div class="grid gap-px bg-gray-200/60 sm:grid-cols-5 dark:bg-white/[0.06]">
                <div class="flex flex-col bg-white p-6 sm:col-span-3 dark:bg-transparent">
                    <div class="flex items-start gap-3">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                            <x-lucide-zap class="size-5 text-primary dark:text-primary-400" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-2xl font-semibold leading-tight text-gray-950 dark:text-white">{{ $planLabel }}</h2>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-micro font-semibold {{ $statusClasses }}">{{ $summary->status }}</span>
                            </div>
                            <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-300">{{ $summary->headline }}</p>
                            @if($summary->card)
                                <p class="mt-1 flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                                    <x-lucide-credit-card class="size-4 shrink-0" />
                                    {{ $summary->card }}
                                </p>
                            @endif
                        </div>
                    </div>

                    @if($showsPlanAction)
                        <div class="mt-6 sm:mt-auto sm:pt-6">
                            <x-filament::button color="gray" size="sm" icon="lucide-external-link" wire:click="managePortal">{{ __('cloud::billing.manage.button') }}</x-filament::button>
                        </div>
                    @endif
                </div>

                <div class="bg-white p-6 sm:col-span-2 dark:bg-transparent">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('cloud::billing.usage.title') }}</p>

                    @if($balance)
                        <p class="mt-2 flex flex-wrap items-baseline gap-x-1.5">
                            <span class="font-display text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ Number::format($remaining) }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.usage.available_label') }}</span>
                        </p>

                        <div role="progressbar" aria-label="{{ __('cloud::billing.usage.monthly_used', ['used' => Number::format($monthlyUsed), 'allowance' => Number::format($allowance)]) }}" aria-valuenow="{{ $usedPercent }}" aria-valuemin="0" aria-valuemax="100" class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-gray-200/80 dark:bg-white/[0.08]">
                            <div class="h-full rounded-full {{ $meterColor }} transition-all duration-500" style="width: {{ $usedPercent }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ __('cloud::billing.usage.monthly_used', ['used' => Number::format($monthlyUsed), 'allowance' => Number::format($allowance)]) }}</p>
                        @if($used > $allowance)
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ __('cloud::billing.usage.total_used', ['credits' => Number::format($used)]) }}</p>
                        @endif
                        @if($balance->period_ends_at)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('cloud::billing.usage.resets', ['date' => $balance->period_ends_at->isoFormat('ll')]) }}</p>
                        @endif
                        @if($purchased > 0)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('cloud::billing.packs.balance_split', ['purchased' => Number::format($purchased)]) }}</p>
                        @endif
                    @else
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('cloud::billing.usage.empty') }}</p>
                    @endif

                    @if($canManageBilling && $availablePacks !== [])
                        <div class="mt-5">
                            <x-filament::dropdown placement="bottom-start">
                                <x-slot name="trigger">
                                    <x-filament::button color="gray" size="sm" icon="lucide-plus">{{ __('cloud::billing.packs.buy_more') }}</x-filament::button>
                                </x-slot>
                                <x-filament::dropdown.list>
                                    @foreach($availablePacks as $key => $pack)
                                        <x-filament::dropdown.list.item wire:click="buyCredits('{{ $key }}')">
                                            {{ __('cloud::billing.packs.buy', ['credits' => Number::format($pack['credits'])]) }}
                                        </x-filament::dropdown.list.item>
                                    @endforeach
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        @if($pastDue && ! $managedPastDue)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 p-5 dark:border-danger-400/20 dark:bg-danger-400/[0.06]">
                <div class="flex gap-3">
                    <x-lucide-circle-exclamation-point class="mt-0.5 h-5 w-5 shrink-0 text-danger-500 dark:text-danger-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-danger-800 dark:text-danger-300">{{ __('cloud::billing.manage.past_due_title') }}</h3>
                        <p class="mt-0.5 text-sm text-danger-700/80 dark:text-danger-400/70">{{ $isManaged ? __('cloud::billing.managed.previous_subscription_past_due') : __('cloud::billing.manage.past_due_body') }}</p>
                        @if($canManageBilling)
                            <div class="mt-3">
                                <x-filament::button color="danger" wire:click="managePortal">{{ __('cloud::billing.manage.button') }}</x-filament::button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if($managedPastDue && $canManageBilling)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 p-5 dark:border-danger-400/20 dark:bg-danger-400/[0.06]">
                <div class="flex gap-3">
                    <x-lucide-circle-exclamation-point class="mt-0.5 h-5 w-5 shrink-0 text-danger-500 dark:text-danger-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-danger-800 dark:text-danger-300">{{ __('cloud::billing.managed.overdue_title', ['plan' => $workspace->plan->marketingLabel()]) }}</h3>
                        <p class="mt-0.5 text-sm text-danger-700/80 dark:text-danger-400/70">{{ __('cloud::billing.managed.overdue_body', ['button' => __('cloud::billing.manage.button')]) }}</p>
                        <div class="mt-3">
                            <x-filament::button color="danger" wire:click="managePortal">{{ __('cloud::billing.manage.button') }}</x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($onGrace)
            <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]">
                <div class="flex gap-3">
                    <x-lucide-clock class="mt-0.5 h-5 w-5 shrink-0 text-warning-500 dark:text-warning-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('cloud::billing.manage.cancel_scheduled_title') }}</h3>
                        <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">
                            {{ $isManaged
                                ? __('cloud::billing.managed.previous_subscription_canceling', ['date' => $subscription?->ends_at?->isoFormat('ll')])
                                : ($isGrandfathered
                                ? __('cloud::billing.manage.cancel_scheduled_legacy_body', ['plan' => $planLabel, 'date' => $subscription?->ends_at?->isoFormat('ll')])
                                : __('cloud::billing.manage.cancel_scheduled_body', ['plan' => $planLabel, 'date' => $subscription?->ends_at?->isoFormat('ll')])) }}
                        </p>
                        @if($this->keepSubscriptionAction->isVisible())
                            <div class="mt-3">{{ $this->keepSubscriptionAction }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if(! $canManageBilling)
            <div class="{{ $card }} flex items-start gap-3 p-6">
                <x-lucide-info class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('cloud::billing.member.ask_owner', ['owner' => $workspace->owner->name]) }}</p>
            </div>
        @elseif($showsManagedCard)
            <div class="{{ $card }} p-6">
                <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ $isEnterprise ? __('cloud::billing.enterprise.title') : __('cloud::billing.managed.title') }}</h3>
                @if($isEnterprise)
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('cloud::billing.enterprise.body') }}</p>
                @endif
                <div class="mt-5 flex flex-wrap gap-3">
                    <x-filament::button tag="a" color="gray" :href="url()->getPublicUrl(LocalizedUrl::route('contact', absolute: false))">{{ __('cloud::billing.enterprise.manage') }}</x-filament::button>
                    @if($managedSubscription || ($canManageSubscription && ! $pastDue))
                        <x-filament::button color="gray" wire:click="managePortal">{{ __('cloud::billing.manage.button') }}</x-filament::button>
                    @endif
                </div>
            </div>
        @elseif($pastDue)
        @elseif($canManageSubscription)
        @else
            @if($onLegacyFree)
                <div class="{{ $card }} flex items-start gap-3 p-5">
                    <x-lucide-shield-check class="mt-0.5 h-5 w-5 shrink-0 text-primary dark:text-primary-400" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('cloud::billing.legacy_free.title') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.legacy_free.body') }}</p>
                    </div>
                </div>
            @endif

            <div x-data="{ yearly: true }" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ __('cloud::billing.upgrade.heading') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.per_workspace') }}</p>
                    </div>
                    <x-cloud::billing.interval-toggle />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach([\App\Enums\Plan::Pro, \App\Enums\Plan::Scale] as $plan)
                        @php
                            $isScale = $plan === \App\Enums\Plan::Scale;
                        @endphp
                        <div
                            id="{{ $plan->value }}-plan"
                            @class([
                                'relative flex flex-col rounded-2xl border bg-white p-6 shadow-sm dark:bg-[var(--surface-card-bg)] dark:shadow-none',
                                'border-primary-300 dark:border-primary-400/40' => $isScale,
                                'border-[var(--surface-card-border)]' => ! $isScale,
                            ])
                        >
                            @if($isScale)
                                <span class="absolute -top-3 left-6 rounded-full bg-primary px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-white">{{ __('cloud::billing.scale_plan.badge') }}</span>
                            @endif

                            <h3 class="font-display text-xl font-semibold text-gray-900 dark:text-white">{{ $plan->marketingLabel() }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __("cloud::billing.{$plan->value}_plan.tagline") }}</p>

                            <div class="mt-5 flex items-baseline gap-1.5">
                                <span class="font-display text-4xl font-bold tracking-tight text-gray-950 dark:text-white" x-text="yearly ? @js('$'.$plan->yearlyPricePerMonth()) : @js('$'.$plan->monthlyPrice())">${{ $plan->yearlyPricePerMonth() }}</span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('cloud::billing.per_month') }}</span>
                                <span x-show="yearly" class="ms-1 rounded-full bg-primary/[0.1] px-1.5 py-0.5 text-[11px] font-semibold leading-none text-primary-700 dark:bg-primary/[0.25] dark:text-primary-300">{{ __('cloud::billing.yearly_save', ['percent' => $plan->yearlySavingPercent()]) }}</span>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400"
                                x-text="yearly ? @js(__('cloud::billing.billed_yearly', ['price' => Number::format($plan->yearlyPrice())])) : @js(__('cloud::billing.billed_monthly'))">
                                {{ __('cloud::billing.billed_yearly', ['price' => Number::format($plan->yearlyPrice())]) }}
                            </p>

                            <div class="mt-5 h-px w-full bg-gray-100 dark:bg-white/[0.06]"></div>

                            @if($isScale)
                                <p class="mt-5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('cloud::billing.scale_plan.includes') }}</p>
                            @endif
                            <ul @class(['grid gap-2.5', 'mt-2.5' => $isScale, 'mt-5' => ! $isScale])>
                                @foreach([__('cloud::billing.allowance.line', ['credits' => Number::format($plan->credits()), 'replies' => Number::format($plan->typicalReplies())]), ...__("cloud::billing.{$plan->value}_plan.features")] as $feature)
                                    <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300">
                                        <x-lucide-check class="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary-400" />
                                        {{ $feature }}
                                    </li>
                                @endforeach
                            </ul>

                            <div class="mt-auto pt-6">
                                @if($trialAvailable && ! $isScale)
                                    <p class="mb-2.5 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('cloud::billing.trial.no_card') }}</p>
                                    <x-filament::button wire:click="startTrial" size="lg" class="w-full justify-center">
                                        {{ __('cloud::billing.trial.start_button') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button type="button" size="lg" :color="$isScale ? 'primary' : 'gray'" class="w-full justify-center" wire:loading.attr="disabled" wire:target="subscribe('{{ $plan->value }}', 'yearly'), subscribe('{{ $plan->value }}', 'monthly')" x-on:click="$wire.subscribe({{ \Illuminate\Support\Js::from($plan->value) }}, yearly ? 'yearly' : 'monthly')">
                                        {{ $onTrial ? __('cloud::billing.subscribe.button') : __('cloud::billing.upgrade.button', ['plan' => $plan->marketingLabel()]) }}
                                    </x-filament::button>
                                    @if($onTrial)
                                        <p class="mt-2.5 text-center text-xs text-gray-500 dark:text-gray-400">{{ __('cloud::billing.upgrade.trial_notice', ['date' => $workspace->trial_ends_at->isoFormat('ll')]) }}</p>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if($showEnterpriseOffer)
            <section class="{{ $card }} p-6" aria-label="{{ __('billing.plans.enterprise') }}">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('billing.plans.enterprise') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ ContactInquiry::Enterprise->price() }}</p>
                    </div>
                    <x-filament::button tag="a" color="gray" :href="url()->getPublicUrl(LocalizedUrl::route('contact', ['plan' => 'enterprise'], absolute: false))">{{ __('cloud::billing.enterprise.contact') }}</x-filament::button>
                </div>
                <p class="mt-4 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('cloud::billing.enterprise.tagline') }} {{ __('cloud::billing.enterprise.terms') }}</p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
