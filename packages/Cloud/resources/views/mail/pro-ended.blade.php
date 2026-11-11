@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => Str::squish($workspace->name)])">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
<h1>{{ $heading }}</h1>

@if($grandfathered)
<p>{{ $managedPlan ? __('mail.pro_ended.managed_ended.grandfathered', ['workspace' => Str::squish($workspace->name)]) : __('mail.pro_ended.grandfathered', ['workspace' => Str::squish($workspace->name)]) }}</p>
@else
<p>{{ __($isTrial ? 'mail.pro_ended.trial_paused' : 'mail.pro_ended.paused', ['workspace' => Str::squish($workspace->name)]) }}</p>
@if($keptOn)

<p>{{ __('mail.pro_ended.kept', ['workspace' => Str::squish($workspace->name), 'date' => $keptOn]) }}</p>
@endif
@if($mailboxSyncPaused)

{{ $managedPlan ? __('mail.pro_ended.managed_ended.mailbox_sync_paused', ['plan' => $managedPlan]) : __('mail.pro_ended.mailbox_sync_paused') }}
@endif
@unless($isTrial)

{{ $managedPlan ? __('mail.pro_ended.managed_ended.restore', ['plan' => $managedPlan]) : __('mail.pro_ended.restore') }}
@endunless
@endif
@if($managedPlan && $grandfathered)

{{ __('mail.pro_ended.managed_ended.restore', ['plan' => $managedPlan]) }}
@endif
@if($isTrial)
@unless($usage->isEmpty())

{{ __('mail.trial_usage.during', ['parts' => $usage->parts()]) }}
@endunless

{{ __('mail.trial_ending.keeps', ['credits' => $credits]) }}
@endif

<x-mail::button :url="$ctaUrl">
{{ $ctaLabel }}
</x-mail::button>
@if($offersExtension)

{{ __('mail.pro_ended.extend', ['days' => \App\Models\Workspace::TRIAL_EXTENSION_DAYS, 'url' => $billingUrl]) }}
@endif
@if($isTrial)

{{ __('mail.trial_ending.questions') }}
@endif
</x-mail::message>
