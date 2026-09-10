<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __("mail.email_code.purposes.{$purpose->value}.preheader") }}</x-slot:preheader>
# {{ __("mail.email_code.purposes.{$purpose->value}.heading") }}

{{ __("mail.email_code.purposes.{$purpose->value}.body") }}

<x-mail::panel>
<div style="text-align:center; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:32px; font-weight:700; letter-spacing:8px; margin-right:-8px;">{{ $code }}</div>
</x-mail::panel>

{{ __('mail.email_code.expires', ['count' => $expiresInMinutes]) }}

{{ __('mail.email_code.browser_hint') }}

{{ __('mail.email_code.latest_only') }}

{{ __('mail.email_code.unsolicited') }}
</x-mail::message>
