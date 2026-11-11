@props(['title', 'total', 'moreUrl', 'tasks' => []])
{{ $title }} ({{ $total }})
@foreach($tasks as $task)
{{ $task['title'] }}: {{ $task['url'] }} ({{ implode(', ', [...($task['priority'] === null ? [] : [$task['priority']['label']]), $task['due'], ...array_column($task['records'], 'label')]) }})

@endforeach
@if($total > count($tasks))
{{ trans_choice('mail.task_digest.more_tasks', $total - count($tasks)) }}: {{ $moreUrl }}

@endif
