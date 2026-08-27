<x-mail::message>
# {{ __('notifications.onboarding.mail_heading', ['name' => $greetingName]) }}

**{{ $stepLabel }}**

{{ $stepDescription }}

<x-mail::button :url="$conversationUrl">
{{ __('notifications.onboarding.mail_button') }}
</x-mail::button>

<x-slot:subcopy>
{{ $companyName }}@if($companyAddress !== '') · {{ $companyAddress }}@endif
</x-slot:subcopy>
</x-mail::message>
