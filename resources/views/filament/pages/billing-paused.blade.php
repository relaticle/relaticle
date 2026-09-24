@php
    $footerLink = 'transition hover:text-gray-950 dark:hover:text-white';
@endphp

<div
    class="h-dvh overflow-y-auto bg-gray-50 dark:bg-gray-950"
    @if($activating) wire:poll.3s="reopenWhenActive" @endif
>
    <div class="flex min-h-full flex-col px-6">
        <header class="flex justify-center pt-10 sm:pt-14">
            <x-brand.logo-lockup size="md" class="text-gray-950 dark:text-white" />
        </header>

        @if($reviewingPlan)
            <main class="flex flex-1 justify-center py-10 sm:py-14" x-data="{
                yearly: true,
                get amount() { return this.yearly ? @js(__('billing.paused.review.amount_yearly')) : @js(__('billing.paused.review.amount_monthly')) },
            }">
                <div class="w-full max-w-4xl">
                    <button type="button" wire:click="$set('step', null)" class="mb-4 inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-950 dark:text-gray-400 dark:hover:text-white">
                        <x-ri-arrow-left-line class="h-4 w-4" />
                        {{ __('billing.paused.review.back') }}
                    </button>

                    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900 dark:shadow-none">
                        <div class="border-b border-gray-200 px-6 py-5 dark:border-white/10">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/[0.08] dark:bg-primary/[0.15]">
                                    <x-ri-flashlight-line class="h-5 w-5 text-primary dark:text-primary-400" />
                                </div>
                                <div>
                                    <h1 class="font-display text-lg font-semibold leading-tight text-gray-950 dark:text-white">{{ __('billing.plans.cloud_pro') }}</h1>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing.pro_plan.tagline') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="grid md:grid-cols-5">
                            <div class="divide-y divide-gray-200 md:col-span-3 dark:divide-white/10">
                                <div class="p-6">
                                    <h2 id="billing-period" class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                        <x-ri-calendar-line class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                                        {{ __('billing.paused.review.billing_period') }}
                                    </h2>

                                    <div class="mt-4 space-y-2.5" role="radiogroup" aria-labelledby="billing-period">
                                        @foreach([true, false] as $isYearly)
                                            <label
                                                class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3.5 transition"
                                                :class="yearly === @js($isYearly)
                                                    ? 'border-primary-500 bg-primary-50/60 ring-1 ring-primary-500 dark:border-primary-400 dark:bg-primary-400/10 dark:ring-primary-400'
                                                    : 'border-gray-200 hover:border-gray-300 dark:border-white/10 dark:hover:border-white/20'"
                                            >
                                                <input type="radio" name="interval" class="h-4 w-4 accent-primary-600" x-model.boolean="yearly" value="{{ $isYearly ? 'true' : 'false' }}" @checked($isYearly)>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">
                                                    {{ $isYearly ? __('billing.pro_plan.yearly') : __('billing.pro_plan.monthly') }}
                                                </span>
                                                @if($isYearly)
                                                    <span class="rounded-full bg-primary/[0.1] px-1.5 py-0.5 text-[11px] font-semibold leading-none text-primary-700 dark:bg-primary/[0.25] dark:text-primary-300">{{ __('billing.pro_plan.yearly_save') }}</span>
                                                @endif
                                                <span class="ms-auto rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-white/[0.06] dark:text-gray-300">
                                                    {{ $isYearly ? __('billing.paused.review.rate_yearly') : __('billing.paused.review.rate_monthly') }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>

                                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('billing.pro_plan.per_workspace') }}</p>
                                </div>

                                <div class="p-6">
                                    <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-950 dark:text-white">
                                        <x-ri-sparkling-line class="h-4 w-4 text-gray-500 dark:text-gray-400" />
                                        {{ __('billing.paused.review.included') }}
                                    </h2>

                                    <ul class="mt-4 grid gap-2.5 sm:grid-cols-2">
                                        @foreach(__('billing.pro_plan.features') as $feature)
                                            <li class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                                                <x-ri-check-line class="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary-400" />
                                                {{ $feature }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>

                            <aside class="border-t border-gray-200 bg-gray-50/70 p-6 md:col-span-2 md:border-s md:border-t-0 dark:border-white/10 dark:bg-white/[0.02]">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('billing.paused.review.summary') }}</h2>
                                    <span class="rounded-md border border-gray-200 bg-white px-2 py-0.5 text-xs text-gray-600 dark:border-white/10 dark:bg-white/[0.04] dark:text-gray-300"
                                        x-text="yearly ? @js(__('billing.paused.review.per_year')) : @js(__('billing.paused.review.per_month'))">{{ __('billing.paused.review.per_year') }}</span>
                                </div>

                                <dl class="mt-5 space-y-3 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-950 dark:text-white">{{ __('billing.paused.review.line_item', ['workspace' => $workspace->name]) }}</dt>
                                        <dd class="text-gray-700 tabular-nums dark:text-gray-300" x-text="amount">{{ __('billing.paused.review.amount_yearly') }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-950 dark:text-white">{{ __('billing.paused.review.credits', ['credits' => number_format(\App\Enums\Plan::Pro->credits())]) }}</dt>
                                        <dd class="text-gray-700 dark:text-gray-300">{{ __('billing.paused.review.credits_included') }}</dd>
                                    </div>

                                    <div class="border-t border-gray-200 dark:border-white/10"></div>

                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-600 dark:text-gray-400">{{ __('billing.paused.review.subtotal') }}</dt>
                                        <dd class="text-gray-700 tabular-nums dark:text-gray-300" x-text="amount">{{ __('billing.paused.review.amount_yearly') }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-gray-600 dark:text-gray-400">{{ __('billing.paused.review.tax') }}</dt>
                                        <dd class="text-gray-500 dark:text-gray-400">{{ __('billing.paused.review.tax_at_checkout') }}</dd>
                                    </div>

                                    <div class="border-t border-gray-200 dark:border-white/10"></div>

                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="font-medium text-gray-950 dark:text-white" x-text="yearly ? @js(__('billing.paused.review.total_yearly')) : @js(__('billing.paused.review.total_monthly'))">{{ __('billing.paused.review.total_yearly') }}</dt>
                                        <dd class="font-display text-2xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white" x-text="amount">{{ __('billing.paused.review.amount_yearly') }}</dd>
                                    </div>
                                </dl>

                                <x-filament::button size="lg" class="mt-6 w-full justify-center"
                                    wire:loading.attr="disabled" wire:target="upgrade" x-on:click="$wire.upgrade(yearly ? 'yearly' : 'monthly')">
                                    {{ __('billing.paused.review.proceed') }}
                                </x-filament::button>

                                <p class="mt-3 flex items-center justify-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    <x-ri-lock-line class="h-3.5 w-3.5" />
                                    {{ __('billing.paused.review.secure') }}
                                </p>
                            </aside>
                        </div>
                    </section>
                </div>
            </main>
        @else
            <main class="flex flex-1 items-center justify-center py-16">
                <div class="w-full max-w-sm text-center">
                    @if($activating)
                        <div x-data="{ waited: false }" x-init="setTimeout(() => waited = true, 60000)" role="status">
                            <div class="flex flex-col items-center gap-4" x-show="! waited">
                                <x-filament::loading-indicator class="h-6 w-6 text-primary" />
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('billing.upgrade.activating') }}</p>
                            </div>

                            <div x-show="waited" x-cloak>
                                <h1 class="font-display text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ __('billing.upgrade.activation_delayed_title') }}</h1>
                                <p class="mt-2 text-[15px] leading-6 text-pretty text-gray-600 dark:text-gray-400">{{ __('billing.upgrade.activation_delayed_body') }}</p>
                            </div>
                        </div>
                    @else
                        <h1 class="font-display text-2xl font-semibold tracking-tight text-balance text-gray-950 dark:text-white">
                            {{ __("billing.paused.heading.{$billingStatus->value}", ['workspace' => $workspace->name]) }}
                        </h1>

                        <p class="mt-2 text-[15px] leading-6 text-pretty text-gray-600 dark:text-gray-400">
                            @if(! $canManageBilling)
                                {{ $workspace->owner
                                    ? __('billing.paused.member_body', ['owner' => $workspace->owner->name, 'workspace' => $workspace->name])
                                    : __('billing.paused.member_body_ownerless', ['workspace' => $workspace->name]) }}
                            @elseif($trialAvailable)
                                {{ __('billing.paused.trial_body', ['workspace' => $workspace->name]) }}
                            @else
                                {{ __('billing.paused.owner_body', ['workspace' => $workspace->name]) }}
                            @endif
                        </p>

                        @if($canManageBilling)
                            <div class="mt-8 flex flex-col gap-3">
                                @if($trialAvailable)
                                    <x-filament::button size="lg" class="w-full justify-center" wire:click="startTrial" wire:loading.attr="disabled" wire:target="startTrial">
                                        {{ __('billing.trial.start_button') }}
                                    </x-filament::button>

                                    <x-filament::button size="lg" color="gray" class="w-full justify-center" wire:click="$set('step', 'plan')">
                                        {{ __('billing.upgrade.now') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button size="lg" icon="ri-arrow-up-circle-line" class="w-full justify-center" wire:click="$set('step', 'plan')">
                                        {{ __('billing.paused.continue') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        @endif

                        @if($otherWorkspaces->isNotEmpty())
                            <div class="mt-5 flex justify-center">
                                <x-filament::dropdown placement="bottom" data-workspace-switcher>
                                    <x-slot name="trigger">
                                        <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition hover:text-gray-950 dark:text-gray-400 dark:hover:text-white">
                                            {{ __('billing.paused.switch') }}
                                            <x-ri-arrow-down-s-line class="h-4 w-4" />
                                        </button>
                                    </x-slot>

                                    <x-filament::dropdown.list>
                                        @foreach($otherWorkspaces as $other)
                                            <x-filament::dropdown.list.item tag="a" :href="filament()->getUrl($other)" :image="filament()->getTenantAvatarUrl($other)">
                                                {{ $other->name }}
                                            </x-filament::dropdown.list.item>
                                        @endforeach
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            </div>
                        @endif

                        <div class="mt-14 flex flex-col items-center gap-3">
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing.paused.help') }}</p>
                            <x-filament::button tag="a" color="gray" icon="ri-customer-service-2-line" class="w-full justify-center"
                                :href="url()->getPublicUrl(route('contact', absolute: false))">
                                {{ __('billing.paused.contact') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </main>
        @endif

        <footer class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 pb-8 text-sm text-gray-500 dark:text-gray-400">
            <span>{{ __('billing.paused.copyright', ['year' => now()->year]) }}</span>
            <a href="{{ url()->getPublicUrl(route('policy.show', absolute: false)) }}" class="{{ $footerLink }}">{{ __('billing.paused.privacy') }}</a>
            <form method="post" action="{{ filament()->getLogoutUrl() }}">
                @csrf
                <button type="submit" class="{{ $footerLink }}">{{ __('billing.paused.sign_out') }}</button>
            </form>
        </footer>
    </div>
</div>
