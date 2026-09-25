<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.reset_password.preheader', ['count' => $count]) }}</x-slot:preheader>
# {{ __('mail.reset_password.heading') }}

{{ __('mail.reset_password.body', ['count' => $count]) }}

<x-mail::button :url="$url">
{{ __('mail.reset_password.cta') }}
</x-mail::button>

{{ __('mail.reset_password.ignore') }}

<x-slot:subcopy>
{{ __('mail.fallback_link') }} <span class="break-all">[{{ $url }}]({{ $url }})</span>
</x-slot:subcopy>
</x-mail::message>
