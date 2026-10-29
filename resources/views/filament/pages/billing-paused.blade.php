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

        <main class="flex flex-1 items-center justify-center py-16" x-data>
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
                        {{ $lapsedPlan
                            ? __('billing.paused.heading.managed_ended', ['plan' => $lapsedPlan->marketingLabel()])
                            : __("billing.paused.heading.{$billingStatus->value}", ['workspace' => $workspace->name]) }}
                    </h1>

                    <p class="mt-2 text-[15px] leading-6 text-pretty text-gray-600 dark:text-gray-400">
                        @if(! $canManageBilling && $workspace->owner && $lapsedPlan)
                            {{ __('billing.paused.member_body_managed', ['owner' => $workspace->owner->name, 'workspace' => $workspace->name, 'plan' => $lapsedPlan->marketingLabel()]) }}
                        @elseif(! $canManageBilling)
                            {{ $workspace->owner
                                ? __('billing.paused.member_body', ['owner' => $workspace->owner->name, 'workspace' => $workspace->name])
                                : __('billing.paused.member_body_ownerless', ['workspace' => $workspace->name]) }}
                        @elseif($lapsedPlan)
                            {{ __('billing.paused.owner_body_managed', ['plan' => $lapsedPlan->marketingLabel(), 'workspace' => $workspace->name]) }}
                        @else
                            {{ __('billing.paused.owner_body', ['workspace' => $workspace->name]) }}
                        @endif
                        @if($mailboxSyncPaused)
                            {{ __('billing.paused.mailbox_sync') }}
                        @endif
                    </p>

                    @if($canManageBilling)
                        <div class="mt-8 flex flex-col gap-3">
                            @if($lapsedPlan)
                                <x-filament::button size="lg" tag="a" icon="ri-customer-service-2-line" class="w-full justify-center"
                                    :href="url()->getPublicUrl(route('contact', ['plan' => $lapsedPlan->value], absolute: false))">
                                    {{ __('billing.paused.restart_managed', ['plan' => $lapsedPlan->marketingLabel()]) }}
                                </x-filament::button>
                            @endif
                            <x-filament::button size="lg" :color="$lapsedPlan ? 'gray' : 'primary'" icon="ri-arrow-up-circle-line" class="w-full justify-center" x-on:click="$dispatch('open-modal', { id: {{ \Illuminate\Support\Js::from(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID) }} })">
                                {{ __('billing.paused.continue') }}
                            </x-filament::button>
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

                    <div @class(['mt-14 flex flex-col items-center gap-3', 'hidden' => $lapsedPlan && $canManageBilling])>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('billing.paused.help') }}</p>
                        <x-filament::button tag="a" color="gray" icon="ri-customer-service-2-line" class="w-full justify-center"
                            :href="url()->getPublicUrl(route('contact', absolute: false))">
                            {{ __('billing.paused.contact') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </main>

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
