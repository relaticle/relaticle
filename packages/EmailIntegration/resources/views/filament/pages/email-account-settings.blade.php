@php
    $account = $this->account();
@endphp

<x-filament-panels::page>
    <div
        @if ($this->shouldPollAccountStatus())
            wire:poll.10s.visible="refreshAccount"
        @endif
    >
        <div class="flex items-start gap-4">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-white shadow-xs ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10">
                <x-filament::icon :icon="$account->provider->getIcon()" class="size-6" />
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="break-all text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
                        {{ $account->email_address }}
                    </h2>

                    @if ($account->is_default)
                        <x-filament::badge color="info" class="shrink-0">
                            {{ __('filament/pages/email-accounts.default_badge') }}
                        </x-filament::badge>
                    @endif
                </div>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('filament/pages/email-account-settings.subheading') }}
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-3">
                @if ($account->showsSyncProgressOnAccountsPage())
                    <div class="max-sm:hidden">
                        <x-email-integration::importing-badge :account="$account" :icon="$this->syncingIcon()" />
                    </div>
                @endif

                {{ $this->accountActions($account->getKey()) }}
            </div>
        </div>

        <div class="ei-email-settings mt-6">
            {{ $this->form }}
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
