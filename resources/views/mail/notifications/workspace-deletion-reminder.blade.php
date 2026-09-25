<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspaceName])">
<x-slot:preheader>{{ __('mail.workspace_deletion_reminder.preheader', ['date' => $date]) }}</x-slot:preheader>
# {{ trans_choice('mail.workspace_deletion_reminder.heading', $days, ['workspace' => $workspaceName, 'days' => $days]) }}

{{ __('mail.workspace_deletion_reminder.final', ['workspace' => $workspaceName, 'date' => $date]) }}

{{ __('mail.workspace_deletion_reminder.cancel') }}

<x-mail::button :url="$settingsUrl">
{{ __('mail.workspace_deletion_reminder.cta') }}
</x-mail::button>
</x-mail::message>
