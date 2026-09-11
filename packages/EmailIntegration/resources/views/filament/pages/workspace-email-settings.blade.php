<x-filament-panels::page>
    <x-filament::tabs :label="__('filament/pages/email-privacy-settings.tabs.aria')" class="ei-tabs-segmented mb-6">
        <x-filament::tabs.item
            :active="$tab === 'visibility'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedNoSymbol"
            wire:click="setTab('visibility')"
        >
            {{ __('filament/pages/email-privacy-settings.tabs.visibility') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$tab === 'sharing'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedShieldCheck"
            wire:click="setTab('sharing')"
        >
            {{ __('filament/pages/email-privacy-settings.tabs.sharing') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$tab === 'record_creation'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedUserPlus"
            wire:click="setTab('record_creation')"
        >
            {{ __('filament/pages/email-privacy-settings.tabs.record_creation') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{ $this->form }}

    <div class="mt-6">
        @if (in_array($tab, ['sharing', 'record_creation'], true))
            {{ $this->saveAction }}
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
