<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspaceName])">
<x-slot:preheader>{{ __('mail.workspace_deletion_cancelled.preheader') }}</x-slot:preheader>
<h1>{{ __('mail.workspace_deletion_cancelled.heading', ['workspace' => $workspaceName]) }}</h1>

<p>{{ __('mail.workspace_deletion_cancelled.body', ['workspace' => $workspaceName]) }}</p>

<x-mail::button :url="$workspaceUrl">
{{ __('mail.workspace_deletion_cancelled.cta', ['workspace' => $workspaceName]) }}
</x-mail::button>
</x-mail::message>
