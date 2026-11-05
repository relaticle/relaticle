<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ __('cloud::inactivity.notice.preheader', ['workspace' => $workspace->name, 'date' => $date]) }}</x-slot:preheader>
# {{ __('cloud::inactivity.notice.heading') }}

{{ __('cloud::inactivity.notice.body', ['workspace' => $workspace->name]) }}

{{ __('cloud::inactivity.notice.date', ['workspace' => $workspace->name, 'date' => $date]) }}

{{ __('cloud::inactivity.notice.warn') }}

<x-mail::button :url="$billingUrl">
{{ __('cloud::inactivity.notice.cta') }}
</x-mail::button>

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
