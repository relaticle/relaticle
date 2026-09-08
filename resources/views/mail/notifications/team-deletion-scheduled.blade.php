<x-mail::message :reason="__('mail.footer.reason.owner', ['team' => $teamName])">
<x-slot:preheader>{{ __('mail.team_deletion_scheduled.preheader', ['date' => $date]) }}</x-slot:preheader>
# {{ __('mail.team_deletion_scheduled.heading', ['team' => $teamName, 'date' => $date]) }}

{{ __('mail.team_deletion_scheduled.removes', ['team' => $teamName]) }}

{{ __('mail.team_deletion_scheduled.cancel') }}

<x-mail::button :url="$settingsUrl">
{{ __('mail.team_deletion_scheduled.cta') }}
</x-mail::button>
</x-mail::message>
