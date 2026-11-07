<x-filament-panels::page>
    <x-email-integration::settings-tabs />

    <div>
        <h2 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            {{ __('filament/pages/email-accounts.title') }}
        </h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ __('filament/pages/email-accounts.subheading') }}
        </p>
    </div>

    <x-filament::section
        :heading="__('filament/pages/email-accounts.sections.connected.heading')"
        :description="$this->connectedSectionDescription()"
    >
        <div
            class="space-y-3"
            @if ($this->isImportingAnyAccount())
                wire:poll.10s.visible="refreshAccounts"
            @endif
        >
            @foreach ($this->connectedAccounts as $account)
                <div wire:key="email-account-{{ $account->getKey() }}" class="space-y-3 rounded-lg border border-gray-200 px-4 py-3 dark:border-white/10">
                    {{-- On a phone the address takes the row with the actions beside it, and the badges drop under it. --}}
                    <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto] sm:gap-x-4">
                        <x-filament::icon :icon="$account->provider->getIcon()" class="h-5 w-5 shrink-0 self-start text-gray-400 max-sm:mt-0.5 sm:self-center" />

                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="break-all text-sm font-medium text-gray-950 dark:text-white">{{ $account->email_address }}</p>
                                @if ($account->is_default)
                                    <x-filament::badge color="info" class="shrink-0 max-sm:hidden">
                                        {{ __('filament/pages/email-accounts.default_badge') }}
                                    </x-filament::badge>
                                @endif
                            </div>
                            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $account->capabilitiesLabel() }}
                            </p>
                        </div>

                        <div class="col-start-2 row-start-2 flex flex-wrap items-center gap-2 sm:col-start-3 sm:row-start-1 sm:justify-end sm:gap-3">
                            @if ($account->is_default)
                                <x-filament::badge color="info" class="sm:hidden">
                                    {{ __('filament/pages/email-accounts.default_badge') }}
                                </x-filament::badge>
                            @endif

                            @if ($account->showsSyncProgressOnAccountsPage())
                                <x-email-integration::importing-badge :account="$account" :icon="$this->syncingIcon()" />
                            @elseif ($account->hasSyncError())
                                @php($syncErrorReason = $account->syncErrorReason())
                                @php($syncErrorLabel = $syncErrorReason ?? ($account->last_synced_at ? __('filament/pages/email-accounts.synced_at', ['time' => $account->last_synced_at->diffForHumans()]) : null))
                                <x-filament::badge
                                    color="warning"
                                    icon="lucide-triangle-exclamation-point"
                                    :tooltip="$syncErrorLabel"
                                    :aria-label="$syncErrorReason"
                                >
                                    {{ __('filament/pages/email-accounts.sync_error.badge') }}
                                </x-filament::badge>
                            @else
                                <x-filament::badge
                                    :color="$account->status->getColor()"
                                    :icon="$account->isActive() ? 'lucide-zap' : 'lucide-triangle-exclamation-point'"
                                    :title="$account->last_synced_at ? __('filament/pages/email-accounts.synced_at', ['time' => $account->last_synced_at->diffForHumans()]) : null"
                                >
                                    {{ $account->isActive() ? __('filament/pages/email-accounts.in_sync') : $account->status->getLabel() }}
                                </x-filament::badge>
                            @endif
                            @if (! $account->hasSend())
                                @php($sendMissingLabel = __('filament/emails/composer.grant_send.heading', ['email' => $account->email_address]).' '.__('filament/emails/composer.grant_send.description'))
                                <x-filament::badge
                                    color="warning"
                                    icon="lucide-triangle-exclamation-point"
                                    :tooltip="$sendMissingLabel"
                                    :aria-label="$sendMissingLabel"
                                />
                            @endif
                        </div>

                        <div class="col-start-3 row-start-1 flex items-center self-start sm:col-start-4 sm:self-center">
                            {{ $this->accountActions($account->getKey()) }}
                        </div>
                    </div>

                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 p-4 dark:border-white/20">
                {{ $this->connectGmailAction }}

                @if ($this->connectAzureAction->isVisible())
                    {{ $this->connectAzureAction }}
                @endif
            </div>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
