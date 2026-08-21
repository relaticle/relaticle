<div>
    <x-filament::section aside>
        <x-slot name="heading">
            {{ __('teams.sections.team_members.title') }}
        </x-slot>
        <x-slot name="description">
            {{ __('teams.sections.team_members.description') }}
        </x-slot>

        {{ $this->table }}
    </x-filament::section>

    <x-filament-actions::modals/>
</div>
