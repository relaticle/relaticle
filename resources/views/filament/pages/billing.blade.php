<x-filament-panels::page>
    @php
        $allowance = $team->plan->credits();
        $used = (int) ($balance?->credits_used ?? 0);
        $usedPercent = $allowance > 0 ? min(100, (int) round($used / $allowance * 100)) : 0;
        $remaining = max(0, $allowance - $used);

        $isSubscribed = $subscription && $subscription->valid();
        $isEnterprise = $team->plan === \App\Enums\Plan::Enterprise && ! $subscription;
        $onTrial = $team->onGenericTrial();
        $trialDaysLeft = $onTrial ? max(0, (int) ceil(now()->floatDiffInDays($team->trial_ends_at))) : 0;

        [$statusLabel, $statusClasses] = match (true) {
            $pastDue => [__('billing.status.past_due'), 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400'],
            $onGrace => [__('billing.status.canceling'), 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400'],
            $onTrial => [__('billing.status.trialing'), 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400'],
            $isSubscribed => [__('billing.status.active'), 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400'],
            $isEnterprise => [__('billing.status.enterprise'), 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'],
            default => [__('billing.status.free'), 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'],
        };

        $meterColor = match (true) {
            $usedPercent >= 100 => 'bg-danger-500',
            $usedPercent >= 80 => 'bg-warning-500',
            default => 'bg-primary',
        };

        $card = 'rounded-2xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] shadow-[0_2px_16px_-6px_rgba(0,0,0,0.05)] dark:shadow-none';
    @endphp

    <div class="mx-auto w-full max-w-4xl space-y-6" @if($activating) wire:poll.3s @endif>

        @if($activating)
            <div class="{{ $card }} flex items-center gap-3 p-5">
                <x-filament::loading-indicator class="h-5 w-5 text-primary" />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('billing.upgrade.activating') }}</span>
            </div>
        @endif

        {{-- Subscription overview: plan, status, usage --}}
        <section class="{{ $card }} overflow-hidden">
            <div class="grid gap-px sm:grid-cols-5 bg-gray-200/60 dark:bg-white/[0.06]">

                {{-- Plan + status --}}
                <div class="sm:col-span-3 bg-white dark:bg-transparent p-6">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                            <x-ri-flashlight-line class="h-5 w-5 text-primary dark:text-primary-400" />
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="font-display text-2xl font-semibold leading-none text-gray-900 dark:text-white">
                                    {{ $team->plan->label() }}
                                </h2>
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusClasses }}">
                                    {{ $statusLabel }}
                                </span>
                            </div>

                            <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                                @if($onTrial)
                                    {{ __('billing.trial.active_title') }} — {{ trans_choice('billing.trial.days_left', $trialDaysLeft, ['days' => $trialDaysLeft]) }}
                                @elseif($onGrace)
                                    {{ __('billing.manage.cancel_scheduled_body', ['date' => $subscription?->ends_at?->toFormattedDateString()]) }}
                                @elseif($isSubscribed)
                                    {{ __('billing.manage.auto_renews') }}
                                @elseif($team->plan === \App\Enums\Plan::Free)
                                    {{ __('billing.free_plan.tagline') }}
                                @else
                                    {{ __('billing.pro_plan.tagline') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Usage meter --}}
                <div class="sm:col-span-2 bg-white dark:bg-transparent p-6">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('billing.usage.title') }}</span>
                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ $usedPercent }}%</span>
                    </div>

                    <div class="mt-2.5 flex items-baseline gap-1.5">
                        <span class="font-display text-xl font-semibold text-gray-900 dark:text-white">{{ number_format($used) }}</span>
                        <span class="text-sm text-gray-400 dark:text-gray-500">/ {{ number_format($allowance) }}</span>
                    </div>

                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.06]">
                        <div class="h-full rounded-full {{ $meterColor }} transition-all duration-500" style="width: {{ $usedPercent }}%"></div>
                    </div>

                    @if($balance?->period_ends_at)
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ __('billing.usage.resets', ['date' => $balance->period_ends_at->toFormattedDateString()]) }}</p>
                    @endif
                </div>
            </div>
        </section>

        {{-- Payment issue --}}
        @if($pastDue)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 p-5 dark:border-danger-400/20 dark:bg-danger-400/[0.06]">
                <div class="flex gap-3">
                    <x-ri-error-warning-fill class="mt-0.5 h-5 w-5 shrink-0 text-danger-500 dark:text-danger-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-danger-800 dark:text-danger-300">{{ __('billing.manage.past_due_title') }}</h3>
                        <p class="mt-0.5 text-sm text-danger-700/80 dark:text-danger-400/70">{{ __('billing.manage.past_due_body') }}</p>
                        @if($isOwner)
                            <div class="mt-3">
                                <x-filament::button color="danger" wire:click="managePortal">{{ __('billing.manage.button') }}</x-filament::button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Cancellation scheduled --}}
        @if($onGrace)
            <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]">
                <div class="flex gap-3">
                    <x-ri-time-line class="mt-0.5 h-5 w-5 shrink-0 text-warning-500 dark:text-warning-400" />
                    <div class="flex-1">
                        <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('billing.manage.cancel_scheduled_title') }}</h3>
                        <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">{{ __('billing.manage.cancel_scheduled_body', ['date' => $subscription?->ends_at?->toFormattedDateString()]) }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Main action by state --}}
        @if(! $isOwner)
            <div class="{{ $card }} flex items-start gap-3 p-6">
                <x-ri-information-line class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing.member.ask_owner', ['owner' => $team->owner->name]) }}</p>
            </div>

        @elseif($isEnterprise)
            <div class="{{ $card }} p-6">
                <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ __('billing.enterprise.title') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.enterprise.body') }}</p>
            </div>

        @elseif($isSubscribed)
            <div class="{{ $card }} p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400">
                            <x-ri-checkbox-circle-fill class="h-5 w-5" />
                        </div>
                        <div>
                            <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ __('billing.manage.title') }}</h3>
                            <p class="mt-0.5 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ __('billing.manage.body') }}</p>
                        </div>
                    </div>
                    <x-filament::button size="lg" icon="heroicon-m-arrow-top-right-on-square" wire:click="managePortal">
                        {{ __('billing.manage.button') }}
                    </x-filament::button>
                </div>
            </div>

        @else
            {{-- Plans: Free vs Pro --}}
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">

                {{-- Free --}}
                <div class="relative flex flex-col overflow-hidden rounded-2xl border border-gray-200/80 bg-white shadow-[0_2px_16px_-6px_rgba(0,0,0,0.05)] dark:border-white/[0.06] dark:bg-white/[0.02] dark:shadow-none">
                    <div class="h-1 bg-gradient-to-r from-gray-200 via-gray-300 to-gray-200 dark:from-white/10 dark:via-white/20 dark:to-white/10"></div>
                    <div class="flex flex-1 flex-col p-7">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gray-100 dark:bg-white/[0.06]">
                                    <x-ri-cloud-line class="h-4.5 w-4.5 text-gray-600 dark:text-gray-400" />
                                </div>
                                <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ __('billing.plans.free') }}</h3>
                            </div>
                            @if($team->plan === \App\Enums\Plan::Free && ! $onTrial)
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ __('billing.current') }}</span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.free_plan.tagline') }}</p>

                        <div class="mt-5 flex items-baseline gap-1">
                            <span class="font-display text-4xl font-bold tracking-tight text-gray-950 dark:text-white">$0</span>
                            <span class="text-sm text-gray-400 dark:text-gray-500">/mo</span>
                        </div>

                        <ul class="mt-6 flex-1 space-y-3">
                            @foreach(__('billing.free_plan.features') as $feature)
                                <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                                    <x-ri-check-line class="mt-0.5 h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-7 flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-gray-50/80 px-4 py-2.5 text-sm font-medium text-gray-500 dark:border-white/[0.06] dark:bg-white/[0.02] dark:text-gray-400">
                            @if($team->plan === \App\Enums\Plan::Free && ! $onTrial)
                                <x-ri-check-line class="h-4 w-4" />
                                {{ __('billing.current') }}
                            @else
                                {{ __('billing.free_plan.after_trial') }}
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Pro --}}
                <div
                    x-data="{ yearly: false }"
                    class="relative flex flex-col overflow-hidden rounded-2xl border border-primary/20 bg-white shadow-[0_4px_32px_-8px_rgba(124,58,237,0.08)] dark:border-primary/15 dark:bg-white/[0.02] dark:shadow-[0_4px_32px_-8px_rgba(124,58,237,0.15)]"
                >
                    <div class="h-1 bg-gradient-to-r from-primary via-purple-500 to-pink-500"></div>
                    <div class="flex flex-1 flex-col p-7">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2.5">
                                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                                    <x-ri-flashlight-line class="h-4.5 w-4.5 text-primary dark:text-primary-400" />
                                </div>
                                <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">{{ __('billing.plans.pro') }}</h3>
                            </div>
                            <span class="inline-flex items-center gap-1 rounded-full bg-primary/[0.08] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-primary-700 dark:bg-primary/[0.15] dark:text-primary-300">
                                <x-ri-star-fill class="h-3 w-3" />
                                {{ __('billing.recommended') }}
                            </span>
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('billing.pro_plan.tagline') }}</p>

                        <div class="mt-5 flex items-baseline gap-1">
                            <span class="font-display text-4xl font-bold tracking-tight text-gray-950 dark:text-white" x-text="yearly ? '$290' : '$29'">$29</span>
                            <span class="text-sm text-gray-400 dark:text-gray-500" x-text="yearly ? '/yr' : '/mo'">/mo</span>
                        </div>
                        <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">{{ __('billing.pro_plan.per_workspace') }}</p>

                        {{-- Monthly / yearly toggle --}}
                        <div class="mt-4 inline-flex w-fit items-center gap-1 rounded-full border border-gray-200/80 p-1 text-xs dark:border-white/[0.08]">
                            <button type="button" @click="yearly = false" :class="!yearly ? 'bg-primary text-white shadow-sm' : 'text-gray-500 dark:text-gray-400'" class="rounded-full px-3 py-1 font-medium transition">{{ __('billing.pro_plan.monthly') }}</button>
                            <button type="button" @click="yearly = true" :class="yearly ? 'bg-primary text-white shadow-sm' : 'text-gray-500 dark:text-gray-400'" class="rounded-full px-3 py-1 font-medium transition">
                                {{ __('billing.pro_plan.yearly') }}
                                <span class="ml-1 text-[10px]" :class="yearly ? 'text-white/80' : 'text-primary-600 dark:text-primary-300'">{{ __('billing.pro_plan.yearly_save') }}</span>
                            </button>
                        </div>

                        <ul class="mt-6 flex-1 space-y-3">
                            @foreach(__('billing.pro_plan.features') as $feature)
                                <li class="flex items-start gap-2.5 text-sm text-gray-600 dark:text-gray-400">
                                    <x-ri-check-line class="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary-400" />
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-7">
                            @if($trialAvailable)
                                <x-filament::button wire:click="startTrial" size="lg" class="w-full justify-center">
                                    {{ __('billing.trial.start_button') }}
                                </x-filament::button>
                                <button type="button" x-on:click="$wire.upgrade(yearly ? 'yearly' : 'monthly')"
                                    class="mt-3 w-full text-center text-sm font-medium text-primary-600 transition hover:text-primary-500 dark:text-primary-400">
                                    {{ __('billing.upgrade.now') }}
                                </button>
                            @else
                                <x-filament::button type="button" size="lg" class="w-full justify-center"
                                    x-on:click="$wire.upgrade(yearly ? 'yearly' : 'monthly')">
                                    {{ $onTrial ? __('billing.subscribe.button') : __('billing.upgrade.button') }}
                                </x-filament::button>
                            @endif
                            <p class="mt-3 text-center text-xs text-gray-400 dark:text-gray-500"
                                x-text="yearly ? '{{ __('billing.pro_plan.billed_yearly') }}' : '{{ __('billing.pro_plan.billed_monthly') }}'">
                                {{ __('billing.pro_plan.billed_monthly') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
