<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.verify_email.preheader') }}</x-slot:preheader>
# {{ __('mail.verify_email.heading') }}

{{ __('mail.verify_email.body') }}

<x-mail::button :url="$url">
{{ __('mail.verify_email.cta') }}
</x-mail::button>

{{ __('mail.verify_email.ignore') }}

<x-slot:subcopy>
{{ __('mail.fallback_link') }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
