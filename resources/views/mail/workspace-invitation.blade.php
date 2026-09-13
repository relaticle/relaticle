@use('Carbon\CarbonInterface')
<x-mail::message :reason="__('mail.footer.reason.invitee', ['email' => $invitation->email, 'workspace' => $workspaceName])">
<x-slot:preheader>{{ __('mail.workspace_invitation.preheader', ['workspace' => $workspaceName, 'role' => $roleName]) }}</x-slot:preheader>
# {{ __('mail.workspace_invitation.heading', ['workspace' => $workspaceName]) }}

@if($inviterName)
{{ __('mail.workspace_invitation.line_with_inviter', ['inviter' => $inviterName, 'workspace' => $workspaceName, 'role' => $roleName]) }}
@else
{{ __('mail.workspace_invitation.line', ['workspace' => $workspaceName, 'role' => $roleName]) }}
@endif

<x-mail::button :url="$acceptUrl">
{{ __('mail.workspace_invitation.cta') }}
</x-mail::button>

@if($invitation->expires_at)
{{ __('mail.workspace_invitation.expiry', ['expiry' => $invitation->expires_at->diffForHumans(['options' => CarbonInterface::ROUND])]) }}
@endif

{{ __('mail.workspace_invitation.ignore') }}
</x-mail::message>
