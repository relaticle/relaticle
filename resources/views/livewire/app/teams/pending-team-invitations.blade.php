<div>
    @if ($this->hasPendingInvitations())
        {{-- A table draws its own card. Without :contained="false" the section
             wraps it in a second one, so the list renders as a card in a card. --}}
        <x-filament::section aside :contained="false">
            <x-slot name="heading">
                {{ __('teams.sections.pending_team_invitations.title') }}
            </x-slot>
            <x-slot name="description">
                {{ __('teams.sections.pending_team_invitations.description') }}
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    @endif

    <x-filament-actions::modals/>
</div>
