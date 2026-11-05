<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspaceName])">
<x-slot:preheader>{{ __('cloud::inactivity.deleted.preheader') }}</x-slot:preheader>
# {{ __('cloud::inactivity.deleted.heading', ['workspace' => $workspaceName]) }}

{{ __('cloud::inactivity.deleted.body', ['workspace' => $workspaceName]) }}

{{ __('cloud::inactivity.deleted.restart') }}

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
