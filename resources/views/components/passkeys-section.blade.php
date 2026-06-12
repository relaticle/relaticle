@assets
    @vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        init() {
            this.supported = Boolean(window.Passkeys?.isSupported?.());

            window.addEventListener('passkeys:ready', () => {
                this.supported = Boolean(window.Passkeys?.isSupported?.());
            }, { once: true });

            $wire.on('passkey-register-confirmed', async ({ name }) => {
                try {
                    await window.Passkeys.register({ name });
                    $wire.loadPasskeys();
                } catch (e) {
                    if (e?.constructor?.name !== 'UserCancelledError') {
                        $wire.call('notifyRegistrationFailed');
                    }
                }
            });

            $wire.on('passkey-confirm-then-register', async ({ name }) => {
                try {
                    await window.Passkeys.verify({
                        routes: {
                            options: '{{ route('passkey.confirm-options') }}',
                            submit: '{{ route('passkey.confirm') }}',
                        },
                    });
                    await window.Passkeys.register({ name });
                    $wire.loadPasskeys();
                } catch (e) {
                    if (e?.constructor?.name !== 'UserCancelledError') {
                        $wire.call('notifyRegistrationFailed');
                    }
                }
            });

            $wire.on('passkey-confirm-then-delete', async ({ passkeyId }) => {
                try {
                    await window.Passkeys.verify({
                        routes: {
                            options: '{{ route('passkey.confirm-options') }}',
                            submit: '{{ route('passkey.confirm') }}',
                        },
                    });
                    await $wire.call('deletePasskeyAfterPasskeyConfirmation', passkeyId);
                } catch (e) {
                    if (e?.constructor?.name !== 'UserCancelledError') {
                        $wire.call('notifyPasskeyConfirmationFailed');
                    }
                }
            });
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

                {{ ($this->deletePasskeyAction)(['passkeyId' => $passkey['id']]) }}
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
