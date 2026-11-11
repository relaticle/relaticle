@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => Str::squish($workspace->name)])">
<x-slot:preheader>{{ __($grandfathered ? 'mail.trial_ending.preheader_grandfathered' : 'mail.trial_ending.preheader', ['workspace' => Str::squish($workspace->name)]) }}</x-slot:preheader>
<h1>{{ __('mail.trial_ending.heading', ['workspace' => Str::squish($workspace->name), 'date' => $endsOnShort]) }}</h1>

{{ __('mail.trial_ending.ends_at', ['datetime' => $endsOnLong, 'zone' => $zone]) }}

@if($grandfathered)
<p>{{ __('mail.trial_ending.grandfathered', ['workspace' => Str::squish($workspace->name)]) }}</p>
@else
<p>{{ __('mail.trial_ending.pauses', ['workspace' => Str::squish($workspace->name)]) }}</p>
@if($deletesWhenInactive)

{{ __('mail.trial_ending.kept') }}
@endif
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
