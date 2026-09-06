<x-mail::message :reason="__('mail.footer.reason.digest')" :settings-url="$settingsUrl" :unsubscribe-url="$unsubscribeUrl">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
# {{ __('mail.task_digest.heading', ['name' => $greetingName]) }}

@foreach($sections as $section)
## {{ $section['name'] }}

@if($section['overdue'] !== [])
<x-mail::list :title="__('mail.task_digest.overdue')" :rows="$section['overdue']" />
@endif
@if($section['upcoming'] !== [])
<x-mail::list :title="__('mail.task_digest.due_today')" :rows="$section['upcoming']" />
@endif
@endforeach

<x-mail::button :url="$tasksUrl">
{{ __('mail.task_digest.cta') }}
</x-mail::button>
</x-mail::message>
