@props(['reason' => null, 'settingsUrl' => null, 'unsubscribeUrl' => null])
<tr>
<td>
<table class="footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<p>{{ config('relaticle.company.name') }}@if((string) config('relaticle.company.address') !== ''), {{ config('relaticle.company.address') }}@endif</p>
@if($reason !== null)
<p>{{ $reason }}</p>
@endif
@if($settingsUrl !== null || $unsubscribeUrl !== null)
<p class="footer-links">
@if($settingsUrl !== null)
<a href="{{ $settingsUrl }}">{{ __('mail.footer.settings') }}</a>
@endif
@if($unsubscribeUrl !== null)
<a href="{{ $unsubscribeUrl }}">{{ __('mail.footer.unsubscribe') }}</a>
@endif
</p>
@endif
<p>{{ __('mail.footer.copyright', ['year' => date('Y'), 'company' => config('relaticle.company.name')]) }}</p>
</td>
</tr>
</table>
</td>
</tr>
