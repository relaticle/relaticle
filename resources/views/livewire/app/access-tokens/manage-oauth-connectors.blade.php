<x-filament::section aside :contained="false">
    <x-slot name="heading">
        {{ __('access-tokens.connectors.title') }}
    </x-slot>
    <x-slot name="description">
        {{ __('access-tokens.connectors.description') }}
    </x-slot>

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament::section>
