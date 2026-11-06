<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ __('cloud::inactivity.reminder.preheader') }}</x-slot:preheader>
# {{ __('cloud::inactivity.reminder.heading', ['workspace' => $workspace->name, 'date' => $date]) }}

{{ __('cloud::inactivity.reminder.body', ['workspace' => $workspace->name, 'date' => $date]) }}

{{ __('cloud::inactivity.reminder.keep') }}

<x-mail::button :url="$billingUrl">
{{ __('cloud::inactivity.warning.cta') }}
</x-mail::button>

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
