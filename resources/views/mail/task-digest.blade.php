<x-mail::message :reason="__('mail.footer.reason.digest')" :settings-url="$settingsUrl" :unsubscribe-url="$unsubscribeUrl">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
# {{ __('mail.task_digest.heading', ['name' => $greetingName]) }}

<p>{{ $summary }}</p>

<p class="sub">{{ __('mail.task_digest.open_hint', ['workspace' => $workspace->name]) }}</p>

<x-mail::button :url="$tasksUrl" align="left">
{{ __('mail.task_digest.cta') }}
</x-mail::button>

@if($overdue !== [])
<x-mail::task-list :title="__('mail.task_digest.overdue')" :tasks="$overdue" tone="overdue" />
@endif
@if($upcoming !== [])
<x-mail::task-list :title="__('mail.task_digest.due_today')" :tasks="$upcoming" />
@endif
</x-mail::message>
