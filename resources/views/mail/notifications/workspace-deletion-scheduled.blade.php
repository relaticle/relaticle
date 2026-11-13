<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspaceName])">
<x-slot:preheader>{{ __('mail.workspace_deletion_scheduled.preheader', ['date' => $date]) }}</x-slot:preheader>
<h1>{{ __('mail.workspace_deletion_scheduled.heading', ['workspace' => $workspaceName, 'date' => $date]) }}</h1>

<p>{{ __('mail.workspace_deletion_scheduled.removes', ['workspace' => $workspaceName]) }}</p>

{{ __('mail.workspace_deletion_scheduled.cancel') }}

<x-mail::button :url="$settingsUrl">
{{ __('mail.workspace_deletion_scheduled.cta') }}
</x-mail::button>
</x-mail::message>
