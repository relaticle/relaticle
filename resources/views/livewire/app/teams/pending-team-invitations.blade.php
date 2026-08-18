<x-filament::section aside>
    <x-slot name="heading">
        {{ __('teams.sections.pending_team_invitations.title') }}
    </x-slot>

    {{ $this->table }}

    <x-filament-actions::modals/>

</x-filament::section>
