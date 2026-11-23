<div>
    <x-filament::modal
        :id="\App\Livewire\App\Workspaces\PendingInvitationsForUser::MODAL_ID"
        icon="lucide-mail-open"
        width="md"
        :close-button="false"
        :close-by-escaping="false"
        :close-by-clicking-away="false"
    >
        @if ($invitation = $this->invitations->first())
            @php($roleName = \App\Enums\WorkspaceRole::labelFor($invitation->role))

            <x-slot name="heading">
                {{ __('workspaces.pending_for_user.heading', ['workspace' => $invitation->workspace->name]) }}
            </x-slot>

            <x-slot name="description">
                @if ($invitation->inviter)
                    {{ __('workspaces.pending_for_user.detail_with_inviter', ['inviter' => $invitation->inviter->name, 'role' => $roleName]) }}
                @else
                    {{ __('workspaces.pending_for_user.detail', ['role' => $roleName]) }}
                @endif
            </x-slot>

            <x-slot name="footerActions">
                <x-filament::button wire:click="accept('{{ $invitation->id }}')">
                    {{ __('workspaces.pending_for_user.accept') }}
                </x-filament::button>

                <x-filament::button color="gray" wire:click="decline('{{ $invitation->id }}')">
                    {{ __('workspaces.pending_for_user.decline') }}
                </x-filament::button>
            </x-slot>

            <div
                wire:key="pending-invitation-{{ $invitation->id }}"
                x-init="$nextTick(() => $dispatch('open-modal', { id: @js(\App\Livewire\App\Workspaces\PendingInvitationsForUser::MODAL_ID) }))"
            ></div>
        @endif
    </x-filament::modal>
</div>
