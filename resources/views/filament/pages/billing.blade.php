<x-filament-panels::page>
    @php
        $purchased = (int) ($balance?->purchased_credits ?? 0);
        $used = (int) ($balance?->credits_used ?? 0);
        $monthlyUsed = min($used, $allowance);
        $remaining = (int) ($balance?->credits_remaining ?? 0);
        $isEnterprise = $team->plan === \App\Enums\Plan::Enterprise;
        $usedPercent = $allowance > 0 ? min(100, (int) round($used / $allowance * 100)) : 0;

        $isSubscribed = $subscription?->valid() ?? false;
        $canManageSubscription = $subscription && ($subscription->valid() || $pastDue);
        $onTrial = ! $isEnterprise && $team->onGenericTrial();
        $onLegacyFree = $isGrandfathered
            && $team->plan === \App\Enums\Plan::Free
            && ! $onTrial
            && ! $isSubscribed;
        $isPaused = ! $hasHostedAccess;
        $isManagedPlan = $isEnterprise || (! $subscription
            && ! $onTrial
            && ! $isPaused
            && ! $onLegacyFree
            && $team->plan !== \App\Enums\Plan::Free);
        $showEnterpriseOffer = $isOwner && ! $isEnterprise && ! $pastDue && ! $onGrace && ! $activating;
        $trialDaysLeft = $onTrial ? max(0, (int) ceil(now()->floatDiffInDays($team->trial_ends_at))) : 0;

        $planLabel = match (true) {
            $isPaused => __('billing.plans.cloud_pro'),
            $onLegacyFree => __('billing.plans.legacy_free'),
            $team->plan === \App\Enums\Plan::Enterprise => __('billing.plans.enterprise'),
            default => __('billing.plans.cloud_pro'),
        };

        [$statusLabel, $statusClasses] = match (true) {
            $isEnterprise => [__('billing.status.managed'), 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'],
            $pastDue => [__('billing.status.past_due'), 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400'],
            $onGrace => [__('billing.status.canceling'), 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400'],
            $onTrial => [__('billing.status.trialing'), 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400'],
            $isSubscribed => [__('billing.status.active'), 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400'],
            $isPaused => [__('billing.status.paused'), 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400'],
            $onLegacyFree => [__('billing.status.grandfathered'), 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'],
            default => [__('billing.status.managed'), 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'],
        };

        $meterColor = match (true) {
            $usedPercent >= 100 => 'bg-danger-500',
            $usedPercent >= 80 => 'bg-warning-500',
            default => 'bg-primary',
        };

        $card = 'rounded-2xl border border-[var(--surface-card-border)] bg-white shadow-sm dark:bg-[var(--surface-card-bg)] dark:shadow-none';
    @endphp

    <div
        class="w-full max-w-2xl space-y-5"
        @if($activating) x-data="{ waited: false }" x-init="setTimeout(() => waited = true, 60000)" @endif
    >
        @if($activating)
            <div class="{{ $card }} flex items-center gap-3 p-5" wire:poll.3s x-show="! waited">
                <x-filament::loading-indicator class="h-5 w-5 text-primary" />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('billing.upgrade.activating') }}</span>
            </div>

            <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]" x-show="waited" x-cloak>
                <div class="flex gap-3">
                    <x-ri-time-line class="mt-0.5 h-5 w-5 shrink-0 text-warning-500 dark:text-warning-400" />
                    <div>
                        <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('billing.upgrade.activation_delayed_title') }}</h3>
                        <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">{{ __('billing.upgrade.activation_delayed_body') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if($creditsFulfilling)
            <div class="{{ $card }} flex items-center gap-3 p-5">
                <x-filament::loading-indicator class="h-5 w-5 text-primary" />
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('billing.packs.fulfilling_title') }}</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.packs.fulfilling_body') }}</p>
                </div>
            </div>
        @endif

        <section class="{{ $card }} overflow-hidden" aria-label="{{ __('billing.title') }}">
            <div class="grid gap-px bg-gray-200/60 sm:grid-cols-5 dark:bg-white/[0.06]">
                <div class="bg-white p-6 sm:col-span-3 dark:bg-transparent">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                            <x-ri-flashlight-line class="h-5 w-5 text-primary dark:text-primary-400" />
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-2xl font-semibold leading-none text-gray-900 dark:text-white">
                                    {{ $planLabel }}
                                </h2>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-micro font-semibold {{ $statusClasses }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>

                            <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                                @if($isManagedPlan)
                                    {{ __('billing.managed.tagline') }}
                                @elseif($onTrial)
                                    {{ __('billing.trial.active_title') }} ({{ trans_choice('billing.trial.days_left', $trialDaysLeft, ['days' => $trialDaysLeft]) }})
                                @elseif($onGrace)
                                    {{ $isGrandfathered
                                        ? __('billing.manage.cancel_scheduled_legacy_body', ['date' => $subscription?->ends_at?->toFormattedDateString()])
                                        : __('billing.manage.cancel_scheduled_body', ['date' => $subscription?->ends_at?->toFormattedDateString()]) }}
                                @elseif($pastDue)
                                    {{ __('billing.manage.past_due_tagline') }}
                                @elseif($isSubscribed)
                                    {{ __('billing.manage.auto_renews') }}
                                @elseif($isPaused)
                                    {{ __('billing.paused.tagline') }}
                                @elseif($onLegacyFree)
                                    {{ __('billing.legacy_free.tagline') }}
                                @else
                                    {{ __('billing.pro_plan.tagline') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                @if($isPaused)
                    <div class="bg-white p-6 sm:col-span-2 dark:bg-transparent">
                        <div class="flex items-start gap-3">
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gray-100 dark:bg-white/[0.06]">
                                <x-ri-lock-line class="h-4.5 w-4.5 text-gray-500 dark:text-gray-400" />
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('billing.paused.data_title') }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('billing.paused.data_body') }}</p>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="bg-white p-6 sm:col-span-2 dark:bg-transparent">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('billing.usage.title') }}</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $usedPercent }}%</span>
                        </div>

                        <div class="mt-2.5 flex items-baseline gap-1.5">
                            <span class="font-display text-xl font-semibold text-gray-900 dark:text-white">{{ number_format($monthlyUsed) }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">/ {{ number_format($allowance) }}</span>
                        </div>

                        <div role="progressbar" aria-label="{{ __('billing.usage.title') }}" aria-valuenow="{{ $usedPercent }}" aria-valuemin="0" aria-valuemax="100" class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200/80 dark:bg-white/[0.08]">
                            <div class="h-full rounded-full {{ $meterColor }} transition-all duration-500" style="width: {{ $usedPercent }}%"></div>
                        </div>

                        @if($balance)
                            <p class="mt-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('billing.usage.available', ['credits' => number_format($remaining)]) }}</p>
                            @if($used > $allowance)
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ __('billing.usage.total_used', ['credits' => number_format($used)]) }}</p>
                            @endif
                        @else
                            <p class="mt-3 text-xs text-gray-600 dark:text-gray-400">{{ __('billing.usage.empty') }}</p>
                        @endif

                        @if($balance?->period_ends_at)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('billing.usage.resets', ['date' => $balance->period_ends_at->toFormattedDateString()]) }}</p>
                        @endif

                        @if($purchased > 0)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('billing.packs.balance_split', ['purchased' => number_format($purchased)]) }}</p>
                        @endif

                        @if($isOwner && $availablePacks !== [])
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach($availablePacks as $key => $pack)
                                    <x-filament::button size="xs" color="gray" wire:click="buyCredits('{{ $key }}')">
                                        {{ __('billing.packs.buy', ['credits' => number_format($pack['credits'])]) }}
                                    </x-filament::button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        @if($pastDue)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 p-5 dark:border-danger-400/20 dark:bg-danger-400/[0.06]">
                <div class="flex gap-3">
                    <x-ri-error-warning-fill class="mt-0.5 h-5 w-5 shrink-0 text-danger-500 dark:text-danger-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-danger-800 dark:text-danger-300">{{ __('billing.manage.past_due_title') }}</h3>
                        <p class="mt-0.5 text-sm text-danger-700/80 dark:text-danger-400/70">{{ $isEnterprise ? __('billing.enterprise.previous_subscription_past_due') : __('billing.manage.past_due_body') }}</p>
                        @if($isOwner)
                            <div class="mt-3">
                                <x-filament::button color="danger" wire:click="managePortal">{{ __('billing.manage.button') }}</x-filament::button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        @if($onGrace)
            <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]">
                <div class="flex gap-3">
                    <x-ri-time-line class="mt-0.5 h-5 w-5 shrink-0 text-warning-500 dark:text-warning-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('billing.manage.cancel_scheduled_title') }}</h3>
                        <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">
                            {{ $isEnterprise
                                ? __('billing.enterprise.previous_subscription_canceling', ['date' => $subscription?->ends_at?->toFormattedDateString()])
                                : ($isGrandfathered
                                ? __('billing.manage.cancel_scheduled_legacy_body', ['date' => $subscription?->ends_at?->toFormattedDateString()])
                                : __('billing.manage.cancel_scheduled_body', ['date' => $subscription?->ends_at?->toFormattedDateString()])) }}
                        </p>
                    </div>
                </div>
            </div>
        @endif

        @if(! $isOwner)
            <div class="{{ $card }} flex items-start gap-3 p-6">
                <x-ri-information-line class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing.member.ask_owner', ['owner' => $team->owner->name]) }}</p>
            </div>
        @elseif($isManagedPlan)
            <div class="{{ $card }} p-6">
                <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ $isEnterprise ? __('billing.enterprise.title') : __('billing.managed.title') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.body') }}</p>
                <div class="mt-5 flex flex-wrap gap-3">
                    <x-filament::button tag="a" color="gray" :href="url()->getPublicUrl(route('contact', absolute: false))">{{ __('billing.enterprise.manage') }}</x-filament::button>
                    @if($canManageSubscription && ! $pastDue)
                        <x-filament::button color="gray" wire:click="managePortal">{{ __('billing.manage.button') }}</x-filament::button>
                    @endif
                </div>
            </div>
        @elseif($pastDue)
        @elseif($canManageSubscription)
            <div class="{{ $card }} p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-1 items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400">
                            <x-ri-checkbox-circle-fill class="h-5 w-5" />
                        </div>
                        <div>
                            <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ __('billing.manage.title') }}</h3>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.manage.body') }}</p>
                        </div>
                    </div>
                    <x-filament::button size="lg" icon="heroicon-m-arrow-top-right-on-square" wire:click="managePortal" class="shrink-0 whitespace-nowrap">
                        {{ __('billing.manage.button') }}
                    </x-filament::button>
                </div>
            </div>
        @else
            @if($isPaused)
                <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]">
                    <div class="flex gap-3">
                        <x-ri-pause-circle-fill class="mt-0.5 h-5 w-5 shrink-0 text-warning-600 dark:text-warning-400" />
                        <div>
                            <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('billing.paused.title') }}</h3>
                            <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">{{ __('billing.paused.body') }}</p>
                        </div>
                    </div>
                </div>
            @elseif($onLegacyFree)
                <div class="{{ $card }} flex items-start gap-3 p-5">
                    <x-ri-shield-check-fill class="mt-0.5 h-5 w-5 shrink-0 text-primary dark:text-primary-400" />
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('billing.legacy_free.title') }}</h3>
                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.legacy_free.body') }}</p>
                    </div>
                </div>
            @endif

            <div
                x-data="{ yearly: true, confirming: false }"
                class="relative flex w-full flex-col rounded-2xl border border-primary-200 bg-white p-6 shadow-sm sm:p-8 dark:border-primary-400/25 dark:bg-[var(--surface-card-bg)] dark:shadow-none"
            >
                <div class="flex flex-1 flex-col">
                    <h3 class="font-display text-xl font-semibold text-gray-900 dark:text-white">{{ __('billing.plans.cloud_pro') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.pro_plan.tagline') }}</p>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-x-6 gap-y-4">
                        <div>
                            <div class="flex items-baseline gap-1.5">
                                <span class="font-display text-4xl font-bold tracking-tight text-gray-950 dark:text-white" x-text="yearly ? '$19' : '$24'">$19</span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">/mo</span>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('billing.pro_plan.per_workspace') }}</p>
                        </div>

                        <x-billing.interval-toggle />
                    </div>

                    <div class="mt-6 h-px w-full bg-gray-100 dark:bg-white/[0.06]"></div>

                    <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                        @foreach(__('billing.pro_plan.features') as $feature)
                            <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-300">
                                <x-ri-check-line class="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary-400" />
                                {{ $feature }}
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-7">
                        <div x-show="! confirming">
                            @if($trialAvailable)
                                <x-filament::button wire:click="startTrial" size="lg" class="w-full justify-center">
                                    {{ __('billing.trial.start_button') }}
                                </x-filament::button>
                                <button type="button" x-on:click="confirming = true"
                                    class="mt-3 w-full text-center text-sm font-medium text-primary-600 transition hover:text-primary-500 dark:text-primary-400">
                                    {{ __('billing.upgrade.now') }}
                                </button>
                            @else
                                <x-filament::button type="button" size="lg" class="w-full justify-center"
                                    x-on:click="confirming = true">
                                    {{ $onTrial
                                        ? __('billing.subscribe.button')
                                        : ($isPaused ? __('billing.upgrade.unlock') : __('billing.upgrade.button')) }}
                                </x-filament::button>
                            @endif
                            <p class="mt-3 text-center text-xs text-gray-500 dark:text-gray-400"
                                x-text="yearly ? @js(__('billing.pro_plan.billed_yearly')) : @js(__('billing.pro_plan.billed_monthly'))">
                                {{ __('billing.pro_plan.billed_yearly') }}
                            </p>
                        </div>

                        <div x-show="confirming" x-cloak class="rounded-xl border border-primary/25 bg-primary/[0.03] p-4 dark:border-primary/20">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('billing.upgrade.confirm_title') }}</p>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('billing.upgrade.confirm_body', ['workspace' => $team->name]) }}</p>
                            <p class="mt-2 text-sm font-medium text-gray-900 dark:text-white"
                                x-text="yearly ? @js(__('billing.pro_plan.billed_yearly')) : @js(__('billing.pro_plan.billed_monthly'))">
                                {{ __('billing.pro_plan.billed_yearly') }}
                            </p>
                            <div class="mt-4 flex flex-col gap-2 sm:flex-row-reverse">
                                <x-filament::button type="button" class="justify-center"
                                    wire:loading.attr="disabled" wire:target="upgrade" x-on:click="$wire.upgrade(yearly ? 'yearly' : 'monthly')">
                                    {{ __('billing.upgrade.confirm_button', ['workspace' => $team->name]) }}
                                </x-filament::button>
                                <x-filament::button type="button" color="gray" class="justify-center"
                                    x-on:click="confirming = false">
                                    {{ __('billing.upgrade.confirm_cancel') }}
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        @if($showEnterpriseOffer)
            <section class="{{ $card }} p-6" aria-label="{{ __('billing.plans.enterprise') }}">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('billing.plans.enterprise') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.starting_price', ['price' => number_format(config('relaticle.enterprise.starting_price_yearly'))]) }}</p>
                    </div>
                    <x-filament::button tag="a" color="gray" :href="url()->getPublicUrl(route('contact', ['plan' => 'enterprise'], absolute: false))">{{ __('billing.enterprise.contact') }}</x-filament::button>
                </div>
                <p class="mt-4 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ __('billing.enterprise.tagline') }} {{ __('billing.enterprise.terms') }}</p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
