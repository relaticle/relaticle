@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => Str::squish($workspace->name)])">
<x-slot:preheader>{{ __('cloud::inactivity.notice.preheader', ['workspace' => Str::squish($workspace->name), 'date' => $date]) }}</x-slot:preheader>
# {{ __('cloud::inactivity.notice.heading') }}

<p>{{ __('cloud::inactivity.notice.body', ['workspace' => Str::squish($workspace->name)]) }}</p>

<p>{{ __('cloud::inactivity.notice.date', ['workspace' => Str::squish($workspace->name), 'date' => $date]) }}</p>

{{ __('cloud::inactivity.notice.warn', ['warning' => \Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery::WARNING_DAYS, 'reminder' => \Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery::REMINDER_DAYS]) }}

<x-mail::button :url="$billingUrl">
{{ __('cloud::inactivity.notice.cta') }}
</x-mail::button>

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
