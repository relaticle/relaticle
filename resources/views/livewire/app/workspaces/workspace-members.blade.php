<div>
    {{-- A table draws its own card. Without :contained="false" the section wraps
         it in a second one, so the list renders as a card inside a card. --}}
    <x-filament::section aside :contained="false">
        <x-slot name="heading">
            {{ __('workspaces.sections.workspace_members.title') }}
        </x-slot>
        <x-slot name="description">
            {{ __('workspaces.sections.workspace_members.description') }}
        </x-slot>

        {{ $this->table }}
    </x-filament::section>

    <x-filament-actions::modals/>
</div>
