<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.account_deletion_scheduled.preheader', ['date' => $date]) }}</x-slot:preheader>
# {{ __('mail.account_deletion_scheduled.heading', ['date' => $date]) }}

{{ __('mail.account_deletion_scheduled.removes') }}

{{ __('mail.account_deletion_scheduled.cancel') }}

<x-mail::button :url="$keepUrl">
{{ __('mail.account_deletion_scheduled.cta') }}
</x-mail::button>
</x-mail::message>
