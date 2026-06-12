<x-mail::message>
# Your Pro trial ends in 3 days

The 14-day Pro trial for **{{ $team->name }}** ends on {{ $team->trial_ends_at?->toFormattedDateString() }}.
Keep all AI models, 2,000 monthly credits, and higher rate limits by subscribing — still no per-seat pricing, one flat price for the whole workspace.

<x-mail::button :url="url('/app/'.$team->slug.'/billing')">
Keep Pro
</x-mail::button>

If you do nothing, the workspace simply returns to the Free plan. Your data is untouched.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
