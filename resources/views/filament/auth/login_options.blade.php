@assets
    @vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        init() {
            this.supported = Boolean(window.Passkeys?.isSupported?.());

            window.addEventListener('passkeys:ready', () => {
                this.supported = Boolean(window.Passkeys?.isSupported?.());
                this.startAutofill();
            }, { once: true });

            if (this.supported) {
                this.startAutofill();
            }
        },
        async startAutofill() {
            if (!window.Passkeys?.autofill) {
                return;
            }

            try {
                const response = await window.Passkeys.autofill({
                    routes: {
                        options: '{{ route('passkey.login-options') }}',
                        submit: '{{ route('passkey.login') }}',
                    },
                });

                if (response?.redirect) {
                    window.location.href = response.redirect;
                }
            } catch (e) {
                if (e?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            }
        },
        async verify() {
            if (!window.Passkeys?.verify || !this.supported) {
                this.error = @js(__('auth.login.unsupported'));

                return;
            }

            this.loading = true;
            this.error = null;

            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route('passkey.login-options') }}',
                        submit: '{{ route('passkey.login') }}',
                    },
                });

                localStorage.setItem('relaticle:last-login', 'passkey');

                window.location.href = response?.redirect ?? '{{ filament()->getPanel('app')->getUrl() }}';
            } catch (e) {
                if (e?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }"
    x-on:passkey-login.window="verify()"
    x-cloak
>
    @feature(App\Features\SocialAuth::class)
        <x-auth.social-buttons />

        <x-auth.or-divider />
    @endfeature

    <div x-show="$wire.authMethod === 'passkey'" x-cloak class="mt-4 space-y-2 text-center">
        <p class="text-sm text-gray-500 dark:text-gray-400" x-show="!error">{{ __('auth.login.passkey_waiting') }}</p>
        <p x-show="error" x-text="error" x-cloak class="text-sm text-danger-600 dark:text-danger-400"></p>
        <x-filament::button type="button" color="gray" size="sm" x-show="error" x-on:click="verify()">
            {{ __('auth.login.passkey_retry') }}
        </x-filament::button>
    </div>
</div>
