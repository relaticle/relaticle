<x-filament::section aside>
    <x-slot name="heading">
        {{ __('access-tokens.sections.manage.title') }}
    </x-slot>
    <x-slot name="description">
        {{ __('access-tokens.sections.manage.description') }}
    </x-slot>

    {{ $this->table }}

    <x-filament-actions::modals />
</x-filament::section>
