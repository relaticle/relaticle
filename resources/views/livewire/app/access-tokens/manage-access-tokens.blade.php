<x-filament::section aside :contained="false">
    <x-slot name="heading">
        {{ __('access-tokens.sections.manage.title') }}
    </x-slot>

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament::section>
