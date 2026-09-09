<x-mail::message :reason="__('mail.footer.reason.owner', ['team' => $teamName])">
<x-slot:preheader>{{ __('mail.team_deletion_reminder.preheader', ['date' => $date]) }}</x-slot:preheader>
# {{ trans_choice('mail.team_deletion_reminder.heading', $days, ['team' => $teamName, 'days' => $days]) }}

{{ __('mail.team_deletion_reminder.final', ['team' => $teamName, 'date' => $date]) }}

{{ __('mail.team_deletion_reminder.cancel') }}

<x-mail::button :url="$settingsUrl">
{{ __('mail.team_deletion_reminder.cta') }}
</x-mail::button>
</x-mail::message>
