<x-mail::message :reason="$teamName === null ? null : __('mail.footer.reason.assignee', ['team' => $teamName])">
<x-slot:preheader>{{ $preheader }}</x-slot:preheader>
# {{ __('mail.task_assigned.heading') }}

<x-mail::list :rows="$rows" />

<x-mail::button :url="$taskUrl">
{{ __('mail.task_assigned.cta') }}
</x-mail::button>
</x-mail::message>
