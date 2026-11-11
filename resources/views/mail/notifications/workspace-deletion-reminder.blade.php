<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspaceName])">
<x-slot:preheader>{{ __('mail.workspace_deletion_reminder.preheader', ['date' => $date]) }}</x-slot:preheader>
<h1>{{ trans_choice('mail.workspace_deletion_reminder.heading', $days, ['workspace' => $workspaceName, 'days' => $days]) }}</h1>

<p>{{ __('mail.workspace_deletion_reminder.final', ['workspace' => $workspaceName, 'date' => $date]) }}</p>

{{ __('mail.workspace_deletion_reminder.cancel') }}

<x-mail::button :url="$settingsUrl">
{{ __('mail.workspace_deletion_reminder.cta') }}
</x-mail::button>
</x-mail::message>
