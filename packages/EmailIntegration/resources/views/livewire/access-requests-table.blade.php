<div>
    <x-filament::tabs :label="__('filament/pages/email-access-requests.tabs.aria')" class="ei-tabs-segmented">
        <x-filament::tabs.item
            :active="$tab === 'incoming'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedInboxArrowDown"
            :badge="$this->pendingIncomingCount > 0 ? ($this->pendingIncomingCount > 99 ? '99+' : (string) $this->pendingIncomingCount) : null"
            badge-color="primary"
            wire:click="setTab('incoming')"
        >
            {{ __('filament/pages/email-access-requests.tabs.incoming') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$tab === 'outgoing'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperAirplane"
            wire:click="setTab('outgoing')"
        >
            {{ __('filament/pages/email-access-requests.tabs.outgoing') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    <div wire:loading.class="pointer-events-none opacity-50" wire:target="setTab">
        {{ $this->table }}
    </div>

    <x-filament-actions::modals />
</div>
