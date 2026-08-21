<div>
    @foreach ($this->invitations as $invitation)
        @php($roleName = \Laravel\Jetstream\Jetstream::findRole($invitation->role)?->name ?? $invitation->role)

        <x-filament::section class="mb-6">
            <x-slot name="heading">
                {{ __('teams.pending_for_user.heading', ['team' => $invitation->team->name]) }}
            </x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($invitation->inviter)
                    {{ __('teams.pending_for_user.detail_with_inviter', ['inviter' => $invitation->inviter->name, 'role' => $roleName]) }}
                @else
                    {{ __('teams.pending_for_user.detail', ['role' => $roleName]) }}
                @endif
            </p>

            <div class="mt-4 flex gap-3">
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
