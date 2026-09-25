@assets
    @vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        init() {
            this.supported = Boolean(window.Passkeys?.isSupported?.())

            window.addEventListener('passkeys:ready', () => {
                this.supported = Boolean(window.Passkeys?.isSupported?.())
            }, { once: true })
        },
        async signIn() {
            this.loading = true
            this.error = null

            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: @js(route('filament.sysadmin.passkeys.login-options')),
                        submit: @js(route('filament.sysadmin.passkeys.login')),
                    },
                })

                window.location.href = response?.redirect ?? @js(\Filament\Facades\Filament::getPanel('sysadmin')->getUrl())
            } catch (e) {
                if (e?.name !== 'UserCancelledError') {
                    this.error = e.message
                }
            } finally {
                this.loading = false
            }
        },
    }"
    x-show="supported"
    x-cloak
    class="mt-6 flex flex-col gap-3"
>
    <div class="flex items-center gap-3">
        <div class="h-px flex-1 bg-gray-200 dark:bg-white/10"></div>
        <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('or') }}</span>
        <div class="h-px flex-1 bg-gray-200 dark:bg-white/10"></div>
    </div>

    <x-filament::button
        type="button"
        color="gray"
        icon="heroicon-m-finger-print"
        x-on:click="signIn()"
        x-bind:disabled="loading"
        class="w-full"
    >
        <span x-show="! loading">{{ __('Sign in with a passkey') }}</span>
        <span x-show="loading" x-cloak>{{ __('Waiting for your passkey...') }}</span>
    </x-filament::button>

    <p x-show="error" x-cloak x-text="error" class="text-sm text-danger-600 dark:text-danger-400"></p>
</div>
