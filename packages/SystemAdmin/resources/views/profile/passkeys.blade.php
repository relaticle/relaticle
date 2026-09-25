@assets
    @vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        registering: false,
        init() {
            $wire.on('sysadmin-passkey-register', async () => {
                if (this.registering) {
                    return
                }

                this.registering = true

                try {
                    await window.Passkeys.register({
                        name: this.deviceLabel(),
                        routes: {
                            options: @js(route('filament.sysadmin.passkeys.options')),
                            submit: @js(route('filament.sysadmin.passkeys.store')),
                        },
                    })

                    $wire.loadPasskeys()
                } catch (e) {
                    if (e?.name !== 'UserCancelledError') {
                        $wire.call('notifyPasskeyRegistrationFailed')
                    }
                } finally {
                    this.registering = false
                    $wire.call('unmountAction')
                }
            })
        },
        deviceLabel() {
            const ua = navigator.userAgent

            const browser = /Edg\//.test(ua) ? 'Edge'
                : /OPR\//.test(ua) ? 'Opera'
                : /Firefox\//.test(ua) ? 'Firefox'
                : /Chrome\//.test(ua) ? 'Chrome'
                : /Safari\//.test(ua) ? 'Safari'
                : null

            const platform = /iPhone|iPad|iPod/.test(ua) ? 'iOS'
                : /Android/.test(ua) ? 'Android'
                : /Mac OS X/.test(ua) ? 'macOS'
                : /Windows/.test(ua) ? 'Windows'
                : /Linux/.test(ua) ? 'Linux'
                : null

            if (browser && platform) {
                return `${browser} on ${platform}`
            }

            return browser ?? platform ?? @js(__('Passkey'))
        },
    }"
>
    @if (filled($this->passkeys))
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->passkeys as $passkey)
                <li class="flex items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                            {{ $passkey['authenticator'] ?? $passkey['name'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Added :when', ['when' => $passkey['created_at_diff']]) }}
                            @if ($passkey['last_used_at_diff'])
                                &middot; {{ __('last used :when', ['when' => $passkey['last_used_at_diff']]) }}
                            @endif
                        </p>
                    </div>

                    {{ ($this->deletePasskeyAction)(['passkeyId' => $passkey['id']]) }}
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('No passkeys yet. Add one to sign in with your device unlock.') }}
        </p>
    @endif
</div>
