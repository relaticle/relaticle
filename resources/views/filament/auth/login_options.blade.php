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
        <template x-if="supported">
            <div class="space-y-2">
                <button
                    type="button"
                    x-on:click="verify()"
                    x-bind:disabled="loading"
                    class="flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                    </svg>
                    <span x-show="!loading">{{ __('Sign in with a passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Authenticating...') }}</span>
                </button>
                <p x-show="error" x-text="error" x-cloak class="text-center text-sm text-red-600 dark:text-red-400"></p>
            </div>
        </template>

        @feature(App\Features\SocialAuth::class)
            <x-auth.social-buttons />
        @endfeature
    </div>

    @if ($socialAuthActive)
        <x-auth.or-divider />
    @else
        <template x-if="supported">
            <x-auth.or-divider />
        </template>
    @endif
</div>
