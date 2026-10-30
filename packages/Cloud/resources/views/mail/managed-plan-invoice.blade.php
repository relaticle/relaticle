<x-mail::message :reason="__('mail.footer.reason.billing_contact', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ __('mail.managed_plan_invoice.preheader', ['plan' => $planName, 'workspace' => $workspace->name]) }}</x-slot:preheader>
# {{ __('mail.managed_plan_invoice.heading', ['plan' => $planName]) }}

{{ __($dueDate ? 'mail.managed_plan_invoice.line' : 'mail.managed_plan_invoice.line_without_due', ['amount' => $formattedAmount, 'workspace' => $workspace->name, 'plan' => $planName, 'date' => $dueDate]) }}

<x-mail::button :url="$hostedInvoiceUrl">
{{ __('mail.managed_plan_invoice.cta') }}
</x-mail::button>
</x-mail::message>
