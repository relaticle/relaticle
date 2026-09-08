<x-mail::message :reason="__('mail.footer.reason.former_member', ['team' => $teamName])">
<x-slot:preheader>{{ __('mail.team_member_removed.preheader') }}</x-slot:preheader>
# {{ __('mail.team_member_removed.heading', ['team' => $teamName]) }}

{{ __('mail.team_member_removed.body', ['team' => $teamName]) }}

<x-mail::button :url="$appUrl">
{{ __('mail.team_member_removed.cta') }}
</x-mail::button>
</x-mail::message>
