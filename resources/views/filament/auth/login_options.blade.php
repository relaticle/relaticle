@assets
    @vite('resources/js/passkeys.js')
@endassets

@php($socialAuthActive = \Laravel\Pennant\Feature::active(\App\Features\SocialAuth::class))

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
                if (e?.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            }
        },
        async verify() {
            this.loading = true;
            this.error = null;

            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route('passkey.login-options') }}',
                        submit: '{{ route('passkey.login') }}',
                    },
                });

                window.location.href = response?.redirect ?? '{{ filament()->getPanel('app')->getUrl() }}';
            } catch (e) {
                if (e?.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }"
    x-cloak
>
    <div class="flex flex-col gap-3">
        @feature(App\Features\SocialAuth::class)
            <x-auth.social-buttons />
        @endfeature

        <template x-if="supported">
            <div class="space-y-2">
                <x-filament::button
                    type="button"
                    color="gray"
                    class="w-full justify-center"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                >
                    <span class="flex">
                        <svg class="w-5 h-5 mr-2" xmlns="http://www.w3.org/2000/svg" viewBox="0 -960 960 960" fill="currentColor">
                            <path d="M120-160v-112q0-34 17.5-62.5T184-378q62-31 126-46.5T440-440q20 0 40 1.5t40 4.5q-4 58 21 109.5t73 84.5v80H120ZM760-40l-60-60v-186q-44-13-72-49.5T600-420q0-58 41-99t99-41q58 0 99 41t41 99q0 45-25.5 80T790-290l50 50-60 60 60 60-80 80ZM440-480q-66 0-113-47t-47-113q0-66 47-113t113-47q66 0 113 47t47 113q0 66-47 113t-113 47Zm300 80q17 0 28.5-11.5T780-440q0-17-11.5-28.5T740-480q-17 0-28.5 11.5T700-440q0 17 11.5 28.5T740-400Z" />
                        </svg>
                        <span x-show="!loading">{{ __('Sign in with a passkey') }}</span>
                        <span x-show="loading" x-cloak>{{ __('Authenticating...') }}</span>
                    </span>
                </x-filament::button>
                <p x-show="error" x-text="error" x-cloak class="text-center text-sm text-red-600 dark:text-red-400"></p>
            </div>
        </template>
    </div>

    @if ($socialAuthActive)
        <x-auth.or-divider />
    @else
        <template x-if="supported">
            <x-auth.or-divider />
        </template>
    @endif
</div>
