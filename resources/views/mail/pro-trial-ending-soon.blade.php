<x-mail::message>
# Your Pro trial ends in 3 days

The 14-day Pro trial for **{{ $team->name }}** ends on {{ $team->trial_ends_at?->toFormattedDateString() }}.
Keep all AI models, 2,000 monthly credits, and higher rate limits by subscribing — still no per-seat pricing, one flat price for the whole workspace.

<x-mail::button :url="url('/app/'.$team->slug.'/billing')">
Keep Pro
</x-mail::button>

@if($team->hosted_free_grandfathered_at)
If you do nothing, this workspace returns to its grandfathered Cloud Free plan. Your data is untouched.
@else
If you do nothing, Cloud access pauses when the trial ends. Your data stays safely stored, and you can subscribe at any time to pick up where you left off.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
