<x-mail::message :reason="__('mail.footer.reason.contact')">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
# {{ __('mail.contact_submission.heading') }}

<x-mail::list :rows="$rows" />

<x-mail::panel>
{{ $data['message'] }}
</x-mail::panel>

<x-mail::button :url="'mailto:'.$data['email']">
{{ __('mail.contact_submission.cta', ['name' => $data['name']]) }}
</x-mail::button>
</x-mail::message>
