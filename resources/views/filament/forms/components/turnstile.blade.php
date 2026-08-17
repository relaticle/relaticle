{{--
    The package's <x-turnstile> wires its callbacks inside a `livewire:initialized`
    listener, which fires once per full page load. The app panel runs in SPA mode,
    so arriving here through any wire:navigate link (e.g. the login page's sign-up
    link) leaves the callbacks unregistered: the widget solves, the token is
    dropped, and every submit fails with the "required" message. Alpine's init()
    runs on every DOM insertion — full load, wire:navigate, and morph alike — so
    the widget is rendered explicitly from here instead.
--}}
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="{
            widgetId: null,

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
                    action: 'register',
                    theme: 'auto',
                    callback: (token) => this.$wire.set(@js($getStatePath()), token),
                    'expired-callback': () => window.turnstile.reset(this.widgetId),
                    'timeout-callback': () => window.turnstile.reset(this.widgetId),
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
