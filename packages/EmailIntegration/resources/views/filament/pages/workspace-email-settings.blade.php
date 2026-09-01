<x-filament-panels::page>
    <x-filament::tabs :label="__('filament/pages/email-privacy-settings.tabs.aria')" class="ei-tabs-segmented mb-6">
        <x-filament::tabs.item
            :active="$tab === 'visibility'"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedEye"
            wire:click="setTab('visibility')"
        >
            {{ __('filament/pages/email-privacy-settings.tabs.visibility') }}
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

    @if ($tab === 'visibility')
        <div class="mt-6">
            {{ $this->saveAction }}
        </div>
    @endif
</x-filament-panels::page>
