<div>
    @if ($this->hasPendingInvitations())
        <x-filament::section aside>
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
