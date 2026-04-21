<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Connected Email Accounts">
            @forelse($this->connectedAccounts as $account)
                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-envelope" class="w-5 h-5 text-gray-400" />
                        <div>
                            <p class="font-medium text-sm text-gray-900 dark:text-white">{{ $account->email_address }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $account->provider->getLabel() }}</p>
                        </div>
                        <x-filament::badge :color="$account->status->getColor()">
                            {{ $account->status->getLabel() }}
                        </x-filament::badge>
                    </div>
                    <div class="flex items-center gap-3">
                        @if ($account->last_synced_at)
                            <span class="text-xs text-gray-400">
                                Synced {{ $account->last_synced_at->diffForHumans() }}
                            </span>
                        @endif
                        @if ($account->status->value === 'reauth_required')
                            {{ ($this->reAuthAction)(['account_id' => $account->id]) }}
                        @endif
                        @if ($account->provider->value === 'gmail')
                            {{ ($this->syncCalendarAction)(['account_id' => $account->id]) }}
                        @endif
                        {{ ($this->editSettingsAction)(['account_id' => $account->id]) }}
                        {{ ($this->disconnectAction)(['account_id' => $account->id]) }}
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No email accounts connected yet.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Connect an Account">
            <div class="flex gap-3">
                {{ $this->connectGmailAction }}
                {{ $this->checkAttachmentAction }}
{{--                {{ $this->connectAzureAction }}--}}
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
