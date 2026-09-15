@php
    $workspace = \Filament\Facades\Filament::getTenant();
    $trialEndsAt = $workspace instanceof \App\Models\Workspace && $workspace->onGenericTrial()
        ? $workspace->trial_ends_at
        : null;
@endphp

<div>
    @if($this->canUpgrade() || $paid)
        <x-filament::modal
            :id="\App\Livewire\App\Billing\UpgradeModal::MODAL_ID"
            width="3xl"
            :close-by-clicking-away="false"
            icon="heroicon-o-arrow-up-circle"
        >
            <x-slot name="heading">{{ __('billing.upgrade.modal_heading') }}</x-slot>

            @if($paid)
                <div class="space-y-4" wire:poll.3s>
                    <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">
                        {{ __('billing.upgrade.paid_title') }}
                    </h3>

                    @if($this->activated())
                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('billing.manage.auto_renews') }}</p>
                        <x-filament::button x-on:click="window.location.reload()">
                            {{ __('billing.upgrade.close') }}
                        </x-filament::button>
                    @else
                        <x-billing.activating />
                    @endif
                </div>
            @else
                <div class="space-y-5">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="font-display text-lg font-semibold text-gray-900 dark:text-white">
                            {{ __('billing.upgrade.modal_plan_title') }}
                        </h3>

                        <div class="flex rounded-lg border border-gray-200 p-0.5 dark:border-white/10" role="group" aria-label="{{ __('billing.upgrade.billing_period') }}">
                            @foreach(['yearly' => __('billing.pro_plan.yearly'), 'monthly' => __('billing.pro_plan.monthly')] as $value => $label)
                                <button
                                    type="button"
                                    wire:key="interval-{{ $value }}"
                                    x-on:click="$dispatch('upgrade-interval-changed', { interval: @js($value) })"
                                    @class([
                                        'rounded-md px-3 py-1 text-sm font-medium transition',
                                        'bg-primary-600 text-white' => $interval === $value,
                                        'text-gray-600 dark:text-gray-300' => $interval !== $value,
                                    ])
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    @if($trialEndsAt !== null)
                        <div class="rounded-xl bg-primary/[0.06] p-3 text-sm text-primary-700 dark:text-primary-300">
                            {{ __('billing.upgrade.trial_notice', ['date' => $trialEndsAt->toFormattedDateString()]) }}
                        </div>
                    @endif

                    @if($error !== null)
                        <div class="rounded-xl bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                            {{ $error }}
                        </div>
                    @endif

                    {{-- x-on:...window is bound by Alpine and removed on teardown.
                         A raw addEventListener in init() would outlive every
                         wire:navigate and fan one open out to each dead instance. --}}
                    <div
                        wire:ignore
                        x-data="upgradeCheckout({
                            publishableKey: @js(config('cashier.key')),
                            modalId: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID),
                        })"
                        x-on:open-modal.window="opened($event)"
                        x-on:upgrade-interval-changed.window="intervalChanged($event)"
                    >
                        <div id="upgrade-checkout" class="min-h-[420px]"></div>
                    </div>
                </div>
            @endif
        </x-filament::modal>
    @endif

    @script
    <script>
        Alpine.data('upgradeCheckout', (config) => ({
            checkout: null,
            mounting: false,

            init() {
                this.$watch('$store.theme', () => this.remount());
            },

            opened(event) {
                // Filament renders modal content eagerly (x-show, not x-if), so
                // mounting on init would open a Stripe session per page load.
                if (event.detail?.id === config.modalId) {
                    this.boot();
                }
            },

            intervalChanged(event) {
                this.$wire.interval = event.detail.interval;
                this.remount();
            },

            async boot() {
                if (this.checkout || this.mounting) {
                    return;
                }

                await this.loadStripeJs();
                await this.mount();
            },

            loadStripeJs() {
                if (window.Stripe) {
                    return Promise.resolve();
                }

                const existing = document.getElementById('stripe-js');

                if (existing) {
                    return new Promise((resolve) => existing.addEventListener('load', resolve, { once: true }));
                }

                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.id = 'stripe-js';
                    script.src = 'https://js.stripe.com/dahlia/stripe.js';
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            },

            async mount() {
                if (this.mounting) {
                    return;
                }

                this.mounting = true;

                try {
                    const stripe = window.Stripe(config.publishableKey);

                    this.checkout = await stripe.createEmbeddedCheckoutPage({
                        fetchClientSecret: async () => {
                            const secret = await this.$wire.createSession(
                                this.$wire.interval,
                                Alpine.store('theme'),
                            );

                            if (! secret) {
                                throw new Error('Could not start checkout.');
                            }

                            return secret;
                        },
                        onComplete: () => this.$wire.markPaid(),
                    });

                    this.checkout.mount('#upgrade-checkout');
                } catch (error) {
                    console.error(error);
                } finally {
                    this.mounting = false;
                }
            },

            async remount() {
                // Nothing to re-price until the modal has actually been opened.
                if (! this.checkout) {
                    return;
                }

                // A session is priced and themed at creation, so both the billing
                // period and the colour scheme need a fresh one.
                this.checkout.destroy();
                this.checkout = null;

                await this.mount();
            },

            destroy() {
                this.checkout?.destroy();
            },
        }))
    </script>
    @endscript
</div>
