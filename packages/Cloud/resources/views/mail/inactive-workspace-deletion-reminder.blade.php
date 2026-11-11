@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => Str::squish($workspace->name)])">
<x-slot:preheader>{{ __('cloud::inactivity.reminder.preheader') }}</x-slot:preheader>
<h1>{{ __('cloud::inactivity.reminder.heading', ['workspace' => Str::squish($workspace->name), 'date' => $date]) }}</h1>

<p>{{ __('cloud::inactivity.reminder.body', ['workspace' => Str::squish($workspace->name), 'date' => $date]) }}</p>

{{ __('cloud::inactivity.reminder.keep') }}

<x-mail::button :url="$billingUrl">
{{ __('cloud::inactivity.warning.cta') }}
</x-mail::button>

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
