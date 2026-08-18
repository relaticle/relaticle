<x-filament::section aside>
    <x-slot name="heading">
        {{ __('teams.sections.team_members.title') }}
    </x-slot>

    {{ $this->table }}

    <x-filament-actions::modals/>

</x-filament::section>
