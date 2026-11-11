@props(['title', 'total', 'moreUrl', 'tasks' => [], 'tone' => 'today'])
<table class="task-column" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="task-column-header"><span class="task-column-dot task-column-dot-{{ $tone }}"></span><span class="task-column-title">{{ $title }}</span><span class="task-column-count">{{ $total }}</span></td>
</tr>
<tr>
<td class="task-column-tray">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach($tasks as $task)
<tr>
<td class="task-card-slot">
<table class="task-card" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="task-card-body">
<a href="{{ $task['url'] }}" class="task-card-link">
<span class="task-card-title">{{ $task['title'] }}</span>
<span class="task-card-chips">
@if($task['priority'] !== null)
<span class="task-chip task-chip-priority"@if($task['priority']['text'] !== null) style="background-color: {{ $task['priority']['background'] }}; border-color: {{ $task['priority']['border'] }}; color: {{ $task['priority']['text'] }};"@endif>@if($task['priority']['dot'] !== null)<span class="task-chip-dot" style="background-color: {{ $task['priority']['dot'] }};"></span>@endif{{ $task['priority']['label'] }}</span>
@endif
<span class="task-chip"><img src="{{ $task['dueIcon'] }}" class="task-chip-icon" width="14" height="14" alt="">{{ $task['due'] }}</span>
@foreach($task['records'] as $record)
<span class="task-chip task-chip-record">@if($record['icon'] !== null)<img src="{{ $record['icon'] }}" class="task-chip-icon" width="14" height="14" alt="">@endif{{ $record['label'] }}</span>
@endforeach
</span>
</a>
</td>
<td class="task-card-chevron-cell" width="26"><a href="{{ $task['url'] }}" class="task-card-chevron">&rsaquo;</a>
</td>
</tr>
</table>
</td>
</tr>
@endforeach
@if($total > count($tasks))
<tr>
<td class="task-more"><a href="{{ $moreUrl }}" class="task-more-link">{{ trans_choice('mail.task_digest.more_tasks', $total - count($tasks)) }}</a></td>
</tr>
@endif
</table>
</td>
</tr>
</table>
