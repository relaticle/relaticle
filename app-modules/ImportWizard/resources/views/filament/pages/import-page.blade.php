<x-filament-panels::page>
    <livewire:import-wizard-new.wizard
        :entity-type="$this->getEntityType()"
        :return-url="$this->getReturnUrl()"
    />
</x-filament-panels::page>
