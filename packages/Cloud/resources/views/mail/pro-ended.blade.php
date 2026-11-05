<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
# {{ $heading }}

@if($grandfathered)
{{ $managedPlan ? __('mail.pro_ended.managed_ended.grandfathered', ['workspace' => $workspace->name]) : __('mail.pro_ended.grandfathered', ['workspace' => $workspace->name]) }}
@else
{{ __($isTrial ? 'mail.pro_ended.trial_paused' : 'mail.pro_ended.paused', ['workspace' => $workspace->name]) }}
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

{{ __('mail.pro_ended.questions') }}
@endif
</x-mail::message>
