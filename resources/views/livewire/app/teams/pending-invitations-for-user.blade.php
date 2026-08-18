<div>
    @foreach ($this->invitations as $invitation)
        <x-filament::section class="mb-6">
            <x-slot name="heading">
                {{ __('teams.pending_for_user.heading', ['team' => $invitation->team->name]) }}
            </x-slot>

            <div class="flex gap-3">
                <x-filament::button wire:click="accept('{{ $invitation->id }}')">
                    {{ __('teams.pending_for_user.accept') }}
                </x-filament::button>

                <x-filament::button color="gray" wire:click="decline('{{ $invitation->id }}')">
                    {{ __('teams.pending_for_user.decline') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endforeach
</div>
