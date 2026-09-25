<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.account_deletion_cancelled.preheader') }}</x-slot:preheader>
# {{ __('mail.account_deletion_cancelled.heading', ['name' => $name]) }}

{{ __('mail.account_deletion_cancelled.body') }}

<x-mail::button :url="$appUrl">
{{ __('mail.account_deletion_cancelled.cta') }}
</x-mail::button>
</x-mail::message>
