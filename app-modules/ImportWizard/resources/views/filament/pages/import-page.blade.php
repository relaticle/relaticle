<x-filament-panels::page>
    <livewire:import-wizard
        :entity-type="static::getEntityType()"
        :return-url="$this->getReturnUrl()"
    />
</x-filament-panels::page>
