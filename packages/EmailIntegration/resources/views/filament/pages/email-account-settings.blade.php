@php
    $account = $this->account();
@endphp

<x-filament-panels::page>
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />

    <div
        @if ($this->isImportingHistory())
            wire:poll.5s="refreshAccount"
        @endif
    >
        <x-filament::section class="-mt-2">
            <x-slot name="heading">
                <div class="flex items-start gap-3">
                    <x-filament::icon :icon="$account->provider->getIcon()" class="mt-0.5 h-6 w-6 shrink-0 text-gray-400" />

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="truncate text-base font-semibold text-gray-950 dark:text-white">
                                {{ $account->email_address }}
                            </span>

                            @if ($account->is_default)
                                <x-filament::badge color="info" class="shrink-0">
                                    {{ __('filament/pages/email-accounts.default_badge') }}
                                </x-filament::badge>
                            @endif
                        </div>

                        <p class="mt-1 text-sm font-normal text-gray-500 dark:text-gray-400">
                            {{ __('filament/pages/email-account-settings.subheading') }}
                        </p>
                        <x-email-integration::account-sync-error :account="$account" class="mt-2" />
                    </div>
                </div>
            </x-slot>

            <x-slot name="afterHeader">
                <div class="flex shrink-0 items-center gap-3">
                    @if ($account->showsSyncProgress())
                        <x-email-integration::importing-badge :account="$account" :icon="$this->syncingIcon()" />
                    @elseif (! $account->isActive())
                        <x-filament::badge
                            :color="$account->status->getColor()"
                            icon="heroicon-m-exclamation-triangle"
                        >
                            {{ $account->status->getLabel() }}
                        </x-filament::badge>
                    @endif

                    {{ $this->accountActions($account->getKey(), includeSettings: false) }}
                </div>
            </x-slot>

            {{ $this->form }}

            <div class="mt-6 flex justify-end">
                {{ $this->saveAction }}
            </div>
        </x-filament::section>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
