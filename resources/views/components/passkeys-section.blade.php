<div
    x-data="{
        supported: false,
        init() {
            this.supported = Boolean(window.Passkeys?.isSupported?.());

            window.addEventListener('passkeys:ready', () => {
                this.supported = Boolean(window.Passkeys?.isSupported?.());
            }, { once: true });

            $wire.on('passkey-register', async () => {
                this.startProcessing(@js(__('profile.sections.passkeys.registering')));

                try {
                    await window.Passkeys.register({ name: this.deviceLabel() });

                    this.startProcessing(@js(__('profile.sections.passkeys.confirmed')));
                    await new Promise((resolve) => setTimeout(resolve, 600));

                    $wire.loadPasskeys();
                    $wire.call('unmountAction');
                } catch (e) {
                    this.stopProcessing();

                    if (e?.name === 'UserCancelledError') {
                        return;
                    }

                    $wire.call('notifyRegistrationFailed');
                    $wire.call('unmountAction');
                }
            });
        },

        deviceLabel() {
            const ua = navigator.userAgent;

            const browser = /Edg\//.test(ua) ? 'Edge'
                : /OPR\//.test(ua) ? 'Opera'
                : /Firefox\//.test(ua) ? 'Firefox'
                : /Chrome\//.test(ua) ? 'Chrome'
                : /Safari\//.test(ua) ? 'Safari'
                : null;

            const platform = /iPhone|iPad|iPod/.test(ua) ? 'iOS'
                : /Android/.test(ua) ? 'Android'
                : /Mac OS X/.test(ua) ? 'macOS'
                : /Windows/.test(ua) ? 'Windows'
                : /Linux/.test(ua) ? 'Linux'
                : null;

            if (browser && platform) {
                return `${browser} on ${platform}`;
            }

            return browser ?? platform ?? @js(__('profile.sections.passkeys.default_name'));
        },

        startProcessing(message) {
            this.modalSubmitForm()?.dispatchEvent(
                new CustomEvent('form-processing-started', { detail: { message } }),
            );
        },

        stopProcessing() {
            this.modalSubmitForm()?.dispatchEvent(new CustomEvent('form-processing-finished'));
        },

        modalSubmitForm() {
            return document.querySelector('.fi-modal-window [type=submit]')?.closest('form') ?? null;
        },
    }"
    class="space-y-4"
>
    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        @forelse ($this->passkeys as $passkey)
            <div class="flex items-center justify-between gap-4 p-4 {{ ! $loop->last ? 'border-b border-gray-200 dark:border-gray-700' : '' }}">
                <div class="space-y-1">
                    <div class="flex items-center gap-2 font-medium text-gray-900 dark:text-white">
                        <span>{{ $passkey['name'] }}</span>
                        @if ($passkey['authenticator'])
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $passkey['authenticator'] }}</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('profile.sections.passkeys.added', ['time' => $passkey['created_at_diff']]) }}
                        @if ($passkey['last_used_at_diff'])
                            <span class="mx-1 opacity-50">·</span>
                            {{ __('profile.sections.passkeys.last_used', ['time' => $passkey['last_used_at_diff']]) }}
                        @endif
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    {{ ($this->renamePasskeyAction)(['passkeyId' => $passkey['id']]) }}
                    {{ ($this->deletePasskeyAction)(['passkeyId' => $passkey['id']]) }}
                </div>
            </div>
        @empty
            <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('profile.sections.passkeys.empty') }}
            </div>
        @endforelse
    </div>

    <template x-if="!supported">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('profile.sections.passkeys.unsupported') }}</p>
    </template>

    <div x-show="supported" x-cloak>
        {{ ($this->registerPasskeyAction)([]) }}
    </div>
</div>
