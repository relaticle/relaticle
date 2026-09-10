{{--
    Rendered from Alpine init() rather than a `livewire:initialized` listener: the
    app panel runs in SPA mode, and that event never fires after a wire:navigate
    arrival, which left the widget solved but its token dropped (PR #472).

    `cf_turnstile_expanded` drives the field's `visibleJs`, so the grid column stays
    out of the form until Cloudflare asks for a checkbox or a submit fails; an empty
    in-flow column still costs one grid gap.
--}}
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="{
            widgetId: null,

            expand(expanded) {
                this.$wire.set(@js($expandedStatePath), expanded, false);
            },

            init() {
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
                    document.head.appendChild(script);
                }
            },

            render() {
                this.widgetId = window.turnstile.render(this.$refs.widget, {
                    sitekey: @js(config('services.turnstile.key')),
                    action: 'signup',
                    appearance: 'interaction-only',
                    theme: 'auto',
                    callback: (token) => this.$wire.set(@js($getStatePath()), token, false),
                    'before-interactive-callback': () => this.expand(true),
                    'after-interactive-callback': () => this.expand(false),
                    'expired-callback': () => window.turnstile.reset(this.widgetId),
                    'timeout-callback': () => window.turnstile.reset(this.widgetId),
                    'error-callback': () => {
                        this.expand(true);
                        this.$wire.set(@js($getStatePath()), null, false);
                    },
                });

                this.$wire.watch(@js($getStatePath()), (value, old) => {
                    if (!!old && ! value) {
                        window.turnstile.reset(this.widgetId);
                    }
                });
            },

            destroy() {
                if (this.widgetId !== null && window.turnstile) {
                    window.turnstile.remove(this.widgetId);
                }
            },
        }"
    >
        <div x-ref="widget"></div>
    </div>
</x-dynamic-component>
