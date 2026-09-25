<x-mail::message :reason="__('mail.footer.reason.former_member', ['workspace' => $workspaceName])">
<x-slot:preheader>{{ __('mail.workspace_member_removed.preheader') }}</x-slot:preheader>
# {{ __('mail.workspace_member_removed.heading', ['workspace' => $workspaceName]) }}

{{ __('mail.workspace_member_removed.body', ['workspace' => $workspaceName]) }}

<x-mail::button :url="$appUrl">
{{ __('mail.workspace_member_removed.cta') }}
</x-mail::button>
</x-mail::message>
