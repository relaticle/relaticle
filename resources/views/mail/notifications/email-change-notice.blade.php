<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.email_change_notice.preheader') }}</x-slot:preheader>
# {{ __('mail.email_change_notice.heading', ['email' => $newEmail]) }}

{{ __('mail.email_change_notice.body', ['email' => $newEmail]) }}

{{ __('mail.email_change_notice.block') }}

<x-mail::button :url="$blockUrl">
{{ __('mail.email_change_notice.cta') }}
</x-mail::button>
</x-mail::message>
