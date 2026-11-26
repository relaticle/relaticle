@props([
    'accountEmail',
    'memberCount',
    'roleDescription' => null,
])

<x-workspaces.invitation-identity
    :label="__('workspaces.invitation.joining_as')"
    :value="$accountEmail"
/>

<ul class="space-y-3">
    <x-workspaces.invitation-fact icon="lucide-users">
        {{ trans_choice('workspaces.invitation.members', $memberCount, ['count' => $memberCount]) }}
    </x-workspaces.invitation-fact>

    @if ($roleDescription)
        <x-workspaces.invitation-fact icon="lucide-shield-check">
            {{ $roleDescription }}
        </x-workspaces.invitation-fact>
    @endif
</ul>
