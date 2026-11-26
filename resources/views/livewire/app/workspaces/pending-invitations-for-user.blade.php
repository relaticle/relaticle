@php($modalId = \App\Livewire\App\Workspaces\PendingInvitationsForUser::MODAL_ID)

<div>
    @if ($asksOnThisPage)
        <x-filament::modal
            :id="$modalId"
            :aria-labelledby="$modalId . '-heading'"
            width="md"
            class="fi-invitation-modal"
            :close-button="false"
            :close-by-escaping="false"
            :close-by-clicking-away="false"
        >
            @if ($invitation)
                @php($workspace = $invitation->workspace)
                @php($roleName = \App\Enums\WorkspaceRole::labelFor($invitation->role))

                <x-workspaces.invitation-panel heading-tag="h2" :heading-id="$modalId . '-heading'">
                    <x-slot:icon>
                        <x-workspaces.invitation-icon :src="$workspace->getFilamentAvatarUrl()" />
                    </x-slot:icon>

                    <x-slot:heading>
                        {{ __('workspaces.accept.ready.heading', ['workspace' => $workspace->name]) }}
                    </x-slot:heading>

                    <x-slot:description>
                        @if ($invitation->inviter)
                            {{ __('workspaces.accept.ready.body_with_inviter', ['inviter' => $invitation->inviter->name, 'workspace' => $workspace->name, 'role' => $roleName]) }}
                        @else
                            {{ __('workspaces.accept.ready.body', ['workspace' => $workspace->name, 'role' => $roleName]) }}
                        @endif
                    </x-slot:description>

                    <x-slot:details>
                        <x-workspaces.invitation-details
                            :account-email="$this->authUser()->email"
                            :member-count="$memberCount"
                            :role-description="\App\Enums\WorkspaceRole::tryFrom($invitation->role)?->description()"
                        />
                    </x-slot:details>

                    <x-slot:actions>
                        <x-filament::button wire:click="accept('{{ $invitation->id }}')" class="w-full justify-center">
                            {{ __('workspaces.accept.ready.action', ['workspace' => $workspace->name]) }}
                        </x-filament::button>

                        <x-filament::link tag="button" color="gray" wire:click="decline('{{ $invitation->id }}')" class="mt-3 block w-full text-center">
                            {{ __('workspaces.pending_for_user.decline') }}
                        </x-filament::link>
                    </x-slot:actions>
                </x-workspaces.invitation-panel>

                <div
                    wire:key="pending-invitation-{{ $invitation->id }}"
                    x-init="$nextTick(() => $dispatch('open-modal', { id: @js($modalId) }))"
                ></div>
            @endif
        </x-filament::modal>
    @endif
</div>
