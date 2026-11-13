<x-filament-panels::page>
    <x-email-integration::settings-layout>
        <x-filament::section
            :contained="false"
            :heading="__('filament/pages/email-accounts.title')"
            :description="__('filament/pages/email-accounts.subheading')"
            class="ei-email-settings"
        >
            <div
                class="space-y-3"
                @if ($this->isImportingAnyAccount())
                    wire:poll.10s.visible="refreshAccounts"
                @endif
            >
                @foreach ($this->connectedAccounts as $account)
                    <div wire:key="email-account-{{ $account->getKey() }}" class="relative space-y-3 rounded-lg border border-gray-200 px-4 py-3 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                        {{-- On a phone the address takes the row with the actions beside it, and the badges drop under it. --}}
                        <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto] sm:gap-x-4">
                            <x-filament::icon :icon="$account->provider->getIcon()" class="h-5 w-5 shrink-0 self-start text-gray-400 max-sm:mt-0.5 sm:self-center" />

                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    {{-- The stretched link makes the whole row open the mailbox settings; badges and the menu sit above it. --}}
                                    <a
                                        href="{{ \Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage::getUrl(['account' => $account->getKey()]) }}"
                                        wire:navigate
                                        class="break-all text-sm font-medium text-gray-950 after:absolute after:inset-0 after:rounded-lg focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-primary-500 dark:text-white"
                                    >{{ $account->email_address }}</a>
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

                            <div class="relative col-start-2 row-start-2 flex flex-wrap items-center gap-2 sm:col-start-3 sm:row-start-1 sm:justify-end sm:gap-3">
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

                            <div class="relative col-start-3 row-start-1 flex items-center self-start sm:col-start-4 sm:self-center">
                                {{ $this->accountActions($account->getKey()) }}
                            </div>
                        </div>

                    </div>
                @endforeach

                @if ($this->hasMailboxCapacity())
                    <div class="flex flex-wrap items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 p-4 dark:border-white/20">
                        {{ $this->connectGmailAction }}

                        @if ($this->connectAzureAction->isVisible())
                            {{ $this->connectAzureAction }}
                        @endif
                    </div>
                @else
                    <div class="mt-6 flex items-center gap-3 rounded-lg bg-info-50 px-3 py-2 text-sm text-info-700 ring-1 ring-info-600/15 dark:bg-info-400/10 dark:text-info-300 dark:ring-info-400/20">
                        <x-filament::icon icon="lucide-info" class="size-5 shrink-0" />

                        <p class="min-w-0 flex-1">{{ $this->mailboxLimitMessage() }}</p>

                        @if ($upgradeUrl = $this->upgradeUrl())
                            <x-filament::button
                                tag="a"
                                :href="$upgradeUrl"
                                color="gray"
                                size="xs"
                                icon="lucide-circle-arrow-up"
                                class="shrink-0"
                            >
                                {{ __('filament/pages/email-accounts.notifications.limit_reached.upgrade') }}
                            </x-filament::button>
                        @endif
                    </div>
                @endif
            </div>
        </x-filament::section>
    </x-email-integration::settings-layout>

    <x-filament-actions::modals />
</x-filament-panels::page>
