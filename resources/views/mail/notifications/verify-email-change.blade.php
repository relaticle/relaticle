<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.verify_email_change.preheader', ['email' => $email]) }}</x-slot:preheader>
# {{ __('mail.verify_email_change.heading', ['email' => $email]) }}

{{ __('mail.verify_email_change.body', ['email' => $email, 'company' => config('relaticle.company.name'), 'count' => $count]) }}

<x-mail::button :url="$url">
{{ __('mail.verify_email_change.cta') }}
</x-mail::button>

{{ __('mail.verify_email_change.ignore') }}

<x-slot:subcopy>
{{ __('mail.fallback_link') }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
