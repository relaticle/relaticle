<x-mail::message :reason="__('mail.footer.reason.account', ['company' => config('relaticle.company.name')])">
<x-slot:preheader>{{ __('mail.account_deletion_reminder.preheader', ['date' => $date]) }}</x-slot:preheader>
# {{ trans_choice('mail.account_deletion_reminder.heading', $days, ['days' => $days]) }}

{{ __('mail.account_deletion_reminder.final', ['date' => $date]) }}

{{ __('mail.account_deletion_reminder.cancel') }}

<x-mail::button :url="$keepUrl">
{{ __('mail.account_deletion_reminder.cta') }}
</x-mail::button>
</x-mail::message>
