<x-filament::section aside :contained="false">
    <x-slot name="heading">
        {{ __('access-tokens.connectors.title') }}
    </x-slot>

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament::section>
