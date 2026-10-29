<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ $managedPlan ? __('mail.pro_ended.managed_ended.preheader', ['plan' => $managedPlan]) : ($grandfathered ? __('mail.pro_ended.preheader_grandfathered') : __('mail.pro_ended.preheader')) }}</x-slot:preheader>
# {{ $heading }}

@if($grandfathered)
{{ $managedPlan ? __('mail.pro_ended.managed_ended.grandfathered', ['workspace' => $workspace->name]) : __('mail.pro_ended.grandfathered', ['workspace' => $workspace->name]) }}
@else
{{ __('mail.pro_ended.paused', ['workspace' => $workspace->name]) }}
@if($mailboxSyncPaused)

{{ $managedPlan ? __('mail.pro_ended.managed_ended.mailbox_sync_paused', ['plan' => $managedPlan]) : __('mail.pro_ended.mailbox_sync_paused') }}
@endif

{{ $managedPlan ? __('mail.pro_ended.managed_ended.restore', ['plan' => $managedPlan]) : __('mail.pro_ended.restore') }}
@endif
@if($managedPlan && $grandfathered)

{{ __('mail.pro_ended.managed_ended.restore', ['plan' => $managedPlan]) }}
@endif

<x-mail::button :url="$ctaUrl">
{{ $ctaLabel }}
</x-mail::button>
</x-mail::message>
