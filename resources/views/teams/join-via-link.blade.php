<x-layouts::invitation :title="__('teams.invite_link.join.heading', ['workspace' => $team->name])">
    <x-slot:icon>
        <x-teams.invitation-icon :src="$team->getFilamentAvatarUrl()" />
    </x-slot:icon>

    <x-slot:heading>
        {{ __('teams.invite_link.join.heading', ['workspace' => $team->name]) }}
    </x-slot:heading>

    <x-slot:description>
        {{ __('teams.invite_link.join.body', ['role' => $roleName]) }}
    </x-slot:description>

    <x-slot:details>
        <x-teams.invitation-identity
            :label="__('teams.invite_link.join.joining_as')"
            :value="$user->email"
        />

        <ul class="space-y-3">
            <x-teams.invitation-fact icon="heroicon-m-user-group">
                {{ trans_choice('teams.invitation.members', $memberCount, ['count' => $memberCount]) }}
            </x-teams.invitation-fact>

            @if ($roleDescription)
                <x-teams.invitation-fact icon="heroicon-m-shield-check">
                    {{ $roleDescription }}
                </x-teams.invitation-fact>
            @endif
        </ul>
    </x-slot:details>

    <x-slot:actions>
        <form method="POST" action="{{ route('teams.join.confirm', ['token' => $token]) }}">
            @csrf

            <x-filament::button type="submit" class="w-full justify-center">
                {{ __('teams.invite_link.join.action') }}
            </x-filament::button>
        </form>

        <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-3 block text-center">
            {{ __('teams.invite_link.join.decline') }}
        </x-filament::link>
    </x-slot:actions>
</x-layouts::invitation>
