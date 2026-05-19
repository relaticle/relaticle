@assets
    @vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        init() {
            this.supported = Boolean(window.Passkeys?.isSupported?.());

            window.addEventListener('passkeys:ready', () => {
                this.supported = Boolean(window.Passkeys?.isSupported?.());
            }, { once: true });
        },
        async register() {
            if (!this.name.trim()) {
                return;
            }

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                $wire.loadPasskeys();
            } catch (e) {
                if (e?.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
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

                <button
                    type="button"
                    wire:click="deletePasskey({{ $passkey['id'] }})"
                    wire:confirm="{{ __('profile.sections.passkeys.remove_confirm') }}"
                    class="text-sm font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                >
                    {{ __('profile.sections.passkeys.remove') }}
                </button>
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

    <template x-if="supported && !showForm">
        <button
            type="button"
            x-on:click="showForm = true"
            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700"
        >
            {{ __('profile.sections.passkeys.add_passkey') }}
        </button>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
            <label class="block">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('profile.sections.passkeys.name_label') }}</span>
                <input
                    type="text"
                    x-model="name"
                    x-on:keydown.enter.prevent="register()"
                    placeholder="{{ __('profile.sections.passkeys.name_placeholder') }}"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900"
                />
            </label>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-red-600 dark:text-red-400"></p>

            <div class="flex gap-2">
                <button
                    type="button"
                    x-on:click="register()"
                    x-bind:disabled="loading || !name.trim()"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 disabled:opacity-50"
                >
                    <span x-show="!loading">{{ __('profile.sections.passkeys.register') }}</span>
                    <span x-show="loading" x-cloak>{{ __('profile.sections.passkeys.registering') }}</span>
                </button>
                <button
                    type="button"
                    x-on:click="cancel()"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    {{ __('profile.sections.passkeys.cancel') }}
                </button>
            </div>
        </div>
    </template>
</div>
