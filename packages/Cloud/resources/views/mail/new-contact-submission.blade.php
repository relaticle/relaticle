@use('Illuminate\Support\Str')
<x-mail::message :reason="__('mail.footer.reason.contact')">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
# {{ __('cloud::mail.contact_submission.heading') }}

<x-mail::list :rows="$rows" />

<x-mail::panel>
<p>{!! nl2br(e($data['message'])) !!}</p>
</x-mail::panel>

<x-mail::button :url="$replyUrl">
{{ __('cloud::mail.contact_submission.cta', ['name' => Str::squish($data['name'])]) }}
</x-mail::button>
</x-mail::message>
