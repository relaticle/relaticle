<x-mail::message :reason="__('mail.footer.reason.owner', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ $grandfathered ? __('mail.pro_ended.preheader_grandfathered') : __('mail.pro_ended.preheader') }}</x-slot:preheader>
# {{ $heading }}

@if($grandfathered)
{{ __('mail.pro_ended.grandfathered', ['workspace' => $workspace->name]) }}
@else
{{ __('mail.pro_ended.paused', ['workspace' => $workspace->name]) }}

{{ __('mail.pro_ended.restore') }}
@endif

<x-mail::button :url="$billingUrl">
{{ $grandfathered ? __('mail.pro_ended.cta_grandfathered') : __('mail.pro_ended.cta') }}
</x-mail::button>
</x-mail::message>
