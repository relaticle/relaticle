@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.former_owner', ['workspace' => Str::squish($workspaceName)])">
<x-slot:preheader>{{ __('cloud::inactivity.deleted.preheader') }}</x-slot:preheader>
<h1>{{ __('cloud::inactivity.deleted.heading', ['workspace' => Str::squish($workspaceName)]) }}</h1>

<p>{{ __('cloud::inactivity.deleted.body', ['workspace' => Str::squish($workspaceName)]) }}</p>

{{ __('cloud::inactivity.deleted.restart') }}

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
