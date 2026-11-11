@props(['title', 'tasks' => []])
{{ $title }} ({{ count($tasks) }})
@foreach($tasks as $task)
{{ $task['title'] }}: {{ $task['url'] }} ({{ implode(', ', [...($task['priority'] === null ? [] : [$task['priority']['label']]), $task['due'], ...array_column($task['records'], 'label')]) }})

@endforeach
