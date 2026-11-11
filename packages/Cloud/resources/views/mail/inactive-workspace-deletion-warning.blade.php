@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => Str::squish($workspace->name)])">
<x-slot:preheader>{{ __('cloud::inactivity.warning.preheader', ['since' => $since]) }}</x-slot:preheader>
<h1>{{ __('cloud::inactivity.warning.heading', ['workspace' => Str::squish($workspace->name), 'date' => $date]) }}</h1>

<p>{{ __('cloud::inactivity.warning.paused', ['workspace' => Str::squish($workspace->name), 'since' => $since]) }}</p>

<p>{{ __('cloud::inactivity.warning.rule', ['workspace' => Str::squish($workspace->name), 'date' => $date]) }}</p>

{{ __('cloud::inactivity.warning.keep') }}

<x-mail::button :url="$billingUrl">
{{ __('cloud::inactivity.warning.cta') }}
</x-mail::button>

{{ __('cloud::inactivity.warning.sign_in') }}

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
