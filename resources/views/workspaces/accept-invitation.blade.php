@if($state === 'ready')
    <x-layouts::invitation :title="__('workspaces.accept.ready.heading', ['workspace' => $workspaceName])">
        <x-slot:icon>
            <x-workspaces.invitation-icon :src="$workspaceAvatarUrl" />
        </x-slot:icon>

        <x-slot:heading>
            {{ __('workspaces.accept.ready.heading', ['workspace' => $workspaceName]) }}
        </x-slot:heading>

        <x-slot:description>
            @if($inviterName)
                {{ __('workspaces.accept.ready.body_with_inviter', ['inviter' => $inviterName, 'workspace' => $workspaceName, 'role' => $roleName]) }}
            @else
                {{ __('workspaces.accept.ready.body', ['workspace' => $workspaceName, 'role' => $roleName]) }}
            @endif
        </x-slot:description>

        <x-slot:details>
            <ul class="space-y-3">
                <x-workspaces.invitation-fact icon="heroicon-m-user-group">
                    {{ trans_choice('workspaces.invitation.members', $memberCount, ['count' => $memberCount]) }}
                </x-workspaces.invitation-fact>

                @if($roleDescription)
                    <x-workspaces.invitation-fact icon="heroicon-m-shield-check">
                        {{ $roleDescription }}
                    </x-workspaces.invitation-fact>
                @endif
            </ul>
        </x-slot:details>

        <x-slot:actions>
            <form method="POST" action="{{ $joinUrl }}">
                @csrf

                <x-filament::button type="submit" class="w-full justify-center">
                    {{ __('workspaces.accept.ready.action', ['workspace' => $workspaceName]) }}
                </x-filament::button>
            </form>

            <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-3 block text-center">
                {{ __('workspaces.accept.ready.decline') }}
            </x-filament::link>
        </x-slot:actions>
    </x-layouts::invitation>
@elseif($state === 'wrong-account')
    <x-layouts::invitation :title="__('workspaces.accept.wrong_account.heading')">
        <x-slot:icon>
            <x-workspaces.invitation-icon icon="heroicon-o-user-circle" />
        </x-slot:icon>

        <x-slot:heading>
            {{ __('workspaces.accept.wrong_account.heading') }}
        </x-slot:heading>

        <x-slot:description>
            {{ __('workspaces.accept.wrong_account.body', ['invited' => $invitedEmail, 'current' => $currentEmail]) }}
        </x-slot:description>

        <x-slot:actions>
            <form method="POST" action="{{ $switchUrl }}">
                @csrf

                <x-filament::button type="submit" class="w-full justify-center">
                    {{ __('workspaces.accept.wrong_account.switch') }}
                </x-filament::button>
            </form>

            <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-3 block text-center">
                {{ __('workspaces.accept.wrong_account.stay') }}
            </x-filament::link>
        </x-slot:actions>
    </x-layouts::invitation>
@else
    <x-layouts::invitation :title="__('workspaces.accept.expired.heading')">
        <x-slot:icon>
            <x-workspaces.invitation-icon icon="heroicon-o-clock" />
        </x-slot:icon>

        <x-slot:heading>
            {{ __('workspaces.accept.expired.heading') }}
        </x-slot:heading>

        <x-slot:description>
            {{ __('workspaces.accept.expired.body') }}
        </x-slot:description>

        <x-slot:actions>
            <x-filament::button tag="a" :href="url()->getAppUrl()" class="w-full justify-center">
                {{ __('workspaces.accept.expired.action') }}
            </x-filament::button>
        </x-slot:actions>
    </x-layouts::invitation>
@endif
