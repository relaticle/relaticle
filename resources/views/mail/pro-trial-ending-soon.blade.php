<x-mail::message :reason="__('mail.footer.reason.owner', ['team' => $team->name])">
<x-slot:preheader>{{ __('mail.trial_ending.preheader') }}</x-slot:preheader>
# {{ __('mail.trial_ending.heading', ['team' => $team->name]) }}

{{ __('mail.trial_ending.ends_on', ['date' => $endsOn]) }}

{{ __('mail.trial_ending.keeps') }} {{ __('mail.trial_ending.flat_price') }}

<x-mail::button :url="$billingUrl">
{{ __('mail.trial_ending.cta') }}
</x-mail::button>

@if($grandfathered)
{{ __('mail.trial_ending.grandfathered', ['team' => $team->name]) }}
@else
{{ __('mail.trial_ending.paused') }}
@endif
</x-mail::message>
