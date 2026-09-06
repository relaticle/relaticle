@use('Carbon\CarbonInterface')
<x-mail::message :reason="__('mail.footer.reason.invitee', ['email' => $invitation->email, 'team' => $teamName])">
<x-slot:preheader>{{ __('mail.team_invitation.preheader', ['team' => $teamName, 'role' => $roleName]) }}</x-slot:preheader>
# {{ __('mail.team_invitation.heading', ['team' => $teamName]) }}

@if($inviterName)
{{ __('mail.team_invitation.line_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
@else
{{ __('mail.team_invitation.line', ['team' => $teamName, 'role' => $roleName]) }}
@endif

<x-mail::button :url="$acceptUrl">
{{ __('mail.team_invitation.cta') }}
</x-mail::button>

@if($invitation->expires_at)
{{ __('mail.team_invitation.expiry', ['expiry' => $invitation->expires_at->diffForHumans(['options' => CarbonInterface::ROUND])]) }}
@endif

{{ __('mail.team_invitation.ignore') }}
</x-mail::message>
