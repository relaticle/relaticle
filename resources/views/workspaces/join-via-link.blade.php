<x-layouts::invitation :title="__('workspaces.invite_link.join.heading', ['workspace' => $workspace->name])">
    <x-slot:icon>
        <x-workspaces.invitation-icon :src="$workspace->getFilamentAvatarUrl()" />
    </x-slot:icon>

    <x-slot:heading>
        {{ __('workspaces.invite_link.join.heading', ['workspace' => $workspace->name]) }}
    </x-slot:heading>

    <x-slot:description>
        {{ __('workspaces.invite_link.join.body', ['role' => $roleName]) }}
    </x-slot:description>

    <x-slot:details>
        <x-workspaces.invitation-identity
            :label="__('workspaces.invite_link.join.joining_as')"
            :value="$user->email"
        />

        <ul class="space-y-3">
            <x-workspaces.invitation-fact icon="heroicon-m-user-group">
                {{ trans_choice('workspaces.invitation.members', $memberCount, ['count' => $memberCount]) }}
            </x-workspaces.invitation-fact>

            @if ($roleDescription)
                <x-workspaces.invitation-fact icon="heroicon-m-shield-check">
                    {{ $roleDescription }}
                </x-workspaces.invitation-fact>
            @endif
        </ul>
    </x-slot:details>

    <x-slot:actions>
        <form method="POST" action="{{ route('workspaces.join.confirm', ['token' => $token]) }}">
            @csrf

            <x-filament::button type="submit" class="w-full justify-center">
                {{ __('workspaces.invite_link.join.action') }}
            </x-filament::button>
        </form>

        <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-3 block text-center">
            {{ __('workspaces.invite_link.join.decline') }}
        </x-filament::link>
    </x-slot:actions>
</x-layouts::invitation>
