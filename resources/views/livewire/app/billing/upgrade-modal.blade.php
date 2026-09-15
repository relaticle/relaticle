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
                <div class="space-y-4">
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

                    {{-- Alpine removes x-on:...window on teardown; a raw
                         addEventListener would outlive every wire:navigate. --}}
                    <div
                        wire:ignore
                        x-data="upgradeCheckout({
                            publishableKey: @js(config('cashier.key')),
                            modalId: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID),
                        })"
                        x-on:open-modal.window="opened($event)"
                        x-on:close-modal.window="closed($event)"
                        x-on:upgrade-interval-changed.window="intervalChanged($event)"
                    >
                        <div x-ref="frame" class="min-h-96"></div>

                        <div x-show="failed" x-cloak class="rounded-xl bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                            <p>{{ __('billing.errors.frame_failed') }}</p>
                            <button type="button" x-on:click="retry()" class="mt-2 font-medium underline">
                                {{ __('billing.errors.retry') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </x-filament::modal>
    @endif

    @script
    <script>
        Alpine.data('upgradeCheckout', (config) => ({
            checkout: null,
            busy: false,
            restartQueued: false,
            failed: false,
            generation: 0,

            init() {
                this.$watch('$store.theme', () => this.reprice());
            },

            opened(event) {
                // Filament renders modal content eagerly (x-show, not x-if), so
                // mounting on init would open a Stripe session per page load.
                if (event.detail?.id === config.modalId) {
                    this.boot();
                }
            },

            closed(event) {
                // A frame left live while closed would let a theme change, including
                // an unattended OS dark-mode switch, silently buy another session.
                if (event.detail?.id === config.modalId) {
                    this.teardown();
                }
            },

            intervalChanged(event) {
                this.$wire.interval = event.detail.interval;
                this.reprice();
            },

            retry() {
                this.failed = false;
                this.boot();
            },

            async boot() {
                if (this.checkout || this.busy) {
                    return;
                }

                const era = this.generation;

                this.busy = true;

                try {
                    await this.loadStripeJs();

                    if (this.stale(era)) {
                        return;
                    }

                    await this.open(await this.secret(), era);
                } catch (error) {
                    this.fail(error, era);
                } finally {
                    this.busy = false;
                }

                await this.drainQueued();
            },

            loadStripeJs() {
                if (window.Stripe) {
                    return Promise.resolve();
                }

                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.id = 'stripe-js';
                    script.src = 'https://js.stripe.com/dahlia/stripe.js';
                    script.onload = resolve;
                    // Dropped on failure so a retry re-adds it. A dead tag left in
                    // place would make every later load await a load event never fired.
                    script.onerror = () => {
                        script.remove();
                        reject(new Error('Stripe.js failed to load.'));
                    };
                    document.head.appendChild(script);
                });
            },

            async secret() {
                const secret = await this.$wire.createSession(
                    this.$wire.interval,
                    Alpine.store('theme'),
                );

                if (! secret) {
                    // The component already rendered why; a second banner would
                    // give the same failure two different explanations.
                    throw Object.assign(new Error('No checkout session.'), { reported: true });
                }

                return secret;
            },

            async open(secret, era) {
                if (this.stale(era)) {
                    return;
                }

                const stripe = window.Stripe(config.publishableKey);

                const checkout = await stripe.createEmbeddedCheckoutPage({
                    fetchClientSecret: () => Promise.resolve(secret),
                    onComplete: () => this.$wire.markPaid(),
                });

                // Closing the modal or navigating away during the round trip must not
                // leave a frame mounted behind it, priced and billable.
                if (this.stale(era) || ! this.$el.isConnected) {
                    checkout.destroy();

                    return;
                }

                this.checkout = checkout;
                this.checkout.mount(this.$refs.frame);
                this.failed = false;
            },

            async reprice() {
                if (this.busy) {
                    this.restartQueued = true;

                    return;
                }

                // Nothing to re-price until the modal has actually been opened.
                if (! this.checkout) {
                    return;
                }

                const era = this.generation;

                this.busy = true;

                try {
                    // The new session is bought before the working frame is discarded,
                    // so a refusal leaves the customer with the one they already had.
                    const secret = await this.secret();

                    if (this.stale(era)) {
                        return;
                    }

                    this.checkout.destroy();
                    this.checkout = null;

                    await this.open(secret, era);
                } catch (error) {
                    this.fail(error, era);
                } finally {
                    this.busy = false;
                }

                await this.drainQueued();
            },

            async drainQueued() {
                if (! this.restartQueued) {
                    return;
                }

                this.restartQueued = false;

                await (this.checkout ? this.reprice() : this.boot());
            },

            stale(era) {
                return era !== this.generation;
            },

            fail(error, era) {
                console.error(error);

                if (this.stale(era) || error?.reported) {
                    return;
                }

                this.failed = this.checkout === null;
            },

            teardown() {
                // Bumped so any in-flight request resolves into a no-op instead of
                // mounting or reporting against a modal the user already closed.
                this.generation++;
                this.checkout?.destroy();
                this.checkout = null;
                this.restartQueued = false;
                this.failed = false;
                this.busy = false;
            },

            destroy() {
                this.teardown();
            },
        }))
    </script>
    @endscript
</div>
