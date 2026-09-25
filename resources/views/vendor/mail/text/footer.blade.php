@props(['reason' => null, 'settingsUrl' => null, 'unsubscribeUrl' => null])
{{ config('relaticle.company.name') }}@if((string) config('relaticle.company.address') !== ''), {{ config('relaticle.company.address') }}@endif

@if($reason !== null)
{{ $reason }}
@endif
@if($settingsUrl !== null)
{{ __('mail.footer.settings') }}: {{ $settingsUrl }}
@endif
@if($unsubscribeUrl !== null)
{{ __('mail.footer.unsubscribe') }}: {{ $unsubscribeUrl }}
@endif
{{ __('mail.footer.copyright', ['year' => date('Y'), 'company' => config('relaticle.company.name')]) }}
