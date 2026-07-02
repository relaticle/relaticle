<x-mail::message>
# {{ $greetingName }}, here's your task digest

@foreach($payload->teams as $team)
## {{ $team->teamName }}

@if(count($team->overdue) > 0)
**Overdue**

@foreach($team->overdue as $task)
- [{{ $task->title }}]({{ $task->editUrl }}) — due {{ $task->dueAt->format('M j, Y') }}
@endforeach
@endif

@if(count($team->upcoming) > 0)
**Upcoming**

@foreach($team->upcoming as $task)
- [{{ $task->title }}]({{ $task->editUrl }}) — due {{ $task->dueAt->format('M j, Y') }}
@endforeach
@endif

@endforeach

<x-mail::button :url="$tasksUrl">
View all my tasks
</x-mail::button>

<x-slot:subcopy>
{{ $companyName }}@if($companyAddress !== '') · {{ $companyAddress }}@endif

{{-- Unsubscribe link + List-Unsubscribe headers are auto-added by Postmark's managed broadcast stream --}}
</x-slot:subcopy>
</x-mail::message>
