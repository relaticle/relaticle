<x-mail::message :reason="__('mail.footer.reason.owner', ['team' => $teamName])">
<x-slot:preheader>{{ __('mail.team_deletion_cancelled.preheader') }}</x-slot:preheader>
# {{ __('mail.team_deletion_cancelled.heading', ['team' => $teamName]) }}

{{ __('mail.team_deletion_cancelled.body', ['team' => $teamName]) }}

<x-mail::button :url="$teamUrl">
{{ __('mail.team_deletion_cancelled.cta', ['team' => $teamName]) }}
</x-mail::button>
</x-mail::message>
