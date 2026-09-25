{{--
    Rendered from Alpine init() rather than a `livewire:initialized` listener: the
    app panel runs in SPA mode, and that event never fires after a wire:navigate
    arrival, which left the widget solved but its token dropped (PR #472).

    `cf_turnstile_expanded` drives the field's `visibleJs`, so the grid column stays
    out of the form until Cloudflare asks for a checkbox, the widget needs to talk to
    the visitor, or a submit fails; an empty in-flow column still costs one grid gap.

    The silent check takes a few seconds and a password manager fills the form in
    under one, so the submit is held until the token exists and replayed the moment
    it arrives. A blocked script or a dead widget surfaces as a message instead of a
    server error pointing at a challenge nobody can see.
--}}
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="{
            widgetId: null,
            token: null,
            status: 'idle',
            interactive: false,
            pendingSubmit: false,
            watchdog: null,
            form: null,
            gate: null,

            expand(expanded) {
                this.$wire.set(@js($expandedStatePath), expanded, false);
            },

            init() {
                this.form = this.$root.closest('form');
                this.gate = (event) => this.holdSubmit(event);
                this.form?.addEventListener('submit', this.gate, { capture: true });

                if (window.turnstile) {
                    this.render();

                    return;
                }

                (window.turnstileRenderQueue ??= []).push(() => this.render());
                window.turnstileReady ??= () => window.turnstileRenderQueue.splice(0).forEach((render) => render());

                if (! window.turnstileScriptInjected) {
                    window.turnstileScriptInjected = true;

                    const script = document.createElement('script');
                    script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=turnstileReady';
                    script.async = true;
                    script.defer = true;
                    script.onerror = () => {
                        window.turnstileScriptInjected = false;
                        this.fail();
                    };
                    document.head.appendChild(script);
                }

                this.armWatchdog();
            },

            render() {
                this.widgetId = window.turnstile.render(this.$refs.widget, {
                    sitekey: @js(config('services.turnstile.key')),
                    action: 'signup',
                    appearance: 'interaction-only',
                    theme: 'auto',
                    callback: (token) => this.accept(token),
                    'before-interactive-callback': () => {
                        this.interactive = true;
                        this.status = 'idle';
                        this.expand(true);
                    },
                    'after-interactive-callback': () => {
                        this.interactive = false;
                    },
                    'expired-callback': () => {
                        this.clearToken();
                        window.turnstile.reset(this.widgetId);
                    },
                    'timeout-callback': () => window.turnstile.reset(this.widgetId),
                    'error-callback': () => this.fail(),
                    'unsupported-callback': () => this.fail(),
                });

                this.$wire.watch(@js($getStatePath()), (value, old) => {
                    if (!!old && ! value) {
                        this.token = null;
                        window.turnstile.reset(this.widgetId);
                    }
                });

                this.armWatchdog();
            },

            holdSubmit(event) {
                if (this.token) {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();

                this.pendingSubmit = true;

                if (this.status !== 'failed') {
                    this.status = 'checking';
                }

                this.expand(true);
                this.armWatchdog();
            },

            accept(token) {
                this.disarmWatchdog();
                this.token = token;
                this.status = 'idle';
                this.$wire.set(@js($getStatePath()), token, false);

                if (this.pendingSubmit) {
                    this.pendingSubmit = false;
                    this.$nextTick(() => this.form?.requestSubmit());

                    return;
                }

                if (! this.interactive) {
                    this.expand(false);
                }
            },

            fail() {
                this.disarmWatchdog();
                this.clearToken();
                this.pendingSubmit = false;
                this.status = 'failed';
                this.expand(true);
            },

            clearToken() {
                this.token = null;
                this.$wire.set(@js($getStatePath()), null, false);
            },

            armWatchdog() {
                this.disarmWatchdog();
                this.watchdog = setTimeout(() => {
                    if (! this.token && ! this.interactive) {
                        this.fail();
                    }
                }, 10000);
            },

            disarmWatchdog() {
                clearTimeout(this.watchdog);
                this.watchdog = null;
            },

            destroy() {
                this.disarmWatchdog();
                this.form?.removeEventListener('submit', this.gate, { capture: true });

                if (this.widgetId !== null && window.turnstile) {
                    window.turnstile.remove(this.widgetId);
                }
            },
        }"
    >
        <div x-ref="widget"></div>

        <p
            x-show="status === 'checking'"
            x-cloak
            class="text-sm text-gray-500 dark:text-gray-400"
            aria-live="polite"
        >
            {{ __('auth.turnstile.checking') }}
        </p>

        <p
            x-show="status === 'failed'"
            x-cloak
            class="fi-fo-field-wrp-error-message"
            role="alert"
        >
            {{ __('auth.turnstile.blocked') }}
        </p>
    </div>
</x-dynamic-component>
