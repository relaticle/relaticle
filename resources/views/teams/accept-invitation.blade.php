@if($state === 'ready')
    <x-layouts::invitation :title="__('teams.accept.ready.heading', ['team' => $teamName])">
        <x-slot:icon>
            <x-teams.invitation-icon :src="$teamAvatarUrl" />
        </x-slot:icon>

        <x-slot:heading>
            {{ __('teams.accept.ready.heading', ['team' => $teamName]) }}
        </x-slot:heading>

        <x-slot:description>
            @if($inviterName)
                {{ __('teams.accept.ready.body_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
            @else
                {{ __('teams.accept.ready.body', ['team' => $teamName, 'role' => $roleName]) }}
            @endif
        </x-slot:description>

        <x-slot:details>
            <ul class="space-y-3">
                <x-teams.invitation-fact icon="heroicon-m-user-group">
                    {{ trans_choice('teams.invitation.members', $memberCount, ['count' => $memberCount]) }}
                </x-teams.invitation-fact>

                @if($roleDescription)
                    <x-teams.invitation-fact icon="heroicon-m-shield-check">
                        {{ $roleDescription }}
                    </x-teams.invitation-fact>
                @endif
            </ul>
        </x-slot:details>

        <x-slot:actions>
            <form method="POST" action="{{ $joinUrl }}">
                @csrf

                <x-filament::button type="submit" class="w-full justify-center">
                    {{ __('teams.accept.ready.action', ['team' => $teamName]) }}
                </x-filament::button>
            </form>

            <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-3 block text-center">
                {{ __('teams.accept.ready.decline') }}
            </x-filament::link>
        </x-slot:actions>
    </x-layouts::invitation>
@elseif($state === 'wrong-account')
    <x-layouts::invitation :title="__('teams.accept.wrong_account.heading')">
        <x-slot:icon>
            <x-teams.invitation-icon icon="heroicon-o-user-circle" />
        </x-slot:icon>

        <x-slot:heading>
            {{ __('teams.accept.wrong_account.heading') }}
        </x-slot:heading>

        <x-slot:description>
            {{ __('teams.accept.wrong_account.body', ['invited' => $invitedEmail, 'current' => $currentEmail]) }}
        </x-slot:description>

        <x-slot:actions>
            <form method="POST" action="{{ $switchUrl }}">
                @csrf

                <x-filament::button type="submit" class="w-full justify-center">
                    {{ __('teams.accept.wrong_account.switch') }}
                </x-filament::button>
            </form>

            <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-3 block text-center">
                {{ __('teams.accept.wrong_account.stay') }}
            </x-filament::link>
        </x-slot:actions>
    </x-layouts::invitation>
@else
    <x-layouts::invitation :title="__('teams.accept.expired.heading')">
        <x-slot:icon>
            <x-teams.invitation-icon icon="heroicon-o-clock" />
        </x-slot:icon>

        <x-slot:heading>
            {{ __('teams.accept.expired.heading') }}
        </x-slot:heading>

        <x-slot:description>
            {{ __('teams.accept.expired.body') }}
        </x-slot:description>

        <x-slot:actions>
            <x-filament::button tag="a" :href="url()->getAppUrl()" class="w-full justify-center">
                {{ __('teams.accept.expired.action') }}
            </x-filament::button>
        </x-slot:actions>
    </x-layouts::invitation>
@endif
