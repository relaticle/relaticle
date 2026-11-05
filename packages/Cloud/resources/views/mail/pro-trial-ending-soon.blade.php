<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ __($grandfathered ? 'mail.trial_ending.preheader_grandfathered' : 'mail.trial_ending.preheader', ['workspace' => $workspace->name]) }}</x-slot:preheader>
# {{ __('mail.trial_ending.heading', ['workspace' => $workspace->name, 'date' => $endsOnShort]) }}

{{ __('mail.trial_ending.ends_at', ['datetime' => $endsOnLong, 'zone' => $zone]) }}

@if($grandfathered)
{{ __('mail.trial_ending.grandfathered', ['workspace' => $workspace->name]) }}
@else
{{ __('mail.trial_ending.pauses', ['workspace' => $workspace->name]) }}

{{ __('mail.trial_ending.kept') }}
@endif

@unless($usage->isEmpty())
{{ __('mail.trial_usage.so_far', ['parts' => $usage->parts()]) }}

@endunless
{{ __('mail.trial_ending.keeps', ['credits' => $credits]) }}

<x-mail::button :url="$billingUrl">
{{ __('mail.trial_ending.cta') }}
</x-mail::button>

{{ __('mail.trial_ending.questions') }}
</x-mail::message>
