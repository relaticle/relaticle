<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ __('cloud::inactivity.warning.preheader', ['since' => $since]) }}</x-slot:preheader>
# {{ __('cloud::inactivity.warning.heading', ['workspace' => $workspace->name, 'date' => $date]) }}

{{ __('cloud::inactivity.warning.paused', ['workspace' => $workspace->name, 'since' => $since]) }}

{{ __('cloud::inactivity.warning.rule', ['workspace' => $workspace->name, 'date' => $date]) }}

{{ __('cloud::inactivity.warning.keep') }}

<x-mail::button :url="$billingUrl">
{{ __('cloud::inactivity.warning.cta') }}
</x-mail::button>

{{ __('cloud::inactivity.warning.sign_in') }}

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
