@props(['title' => null, 'rows' => []])
<table class="list" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@if($title !== null)
<tr>
<td class="list-title">{{ $title }}</td>
</tr>
@endif
@foreach($rows as $row)
<tr>
<td class="list-row">
<div class="list-label">
@if(($row['url'] ?? null) !== null)
<a href="{{ $row['url'] }}">{{ $row['label'] }}</a>
@elseif(($row['value'] ?? null) !== null)
<strong>{{ $row['label'] }}</strong>
@else
{{ $row['label'] }}
@endif
@if(($row['value'] ?? null) !== null)
<span class="list-value">{{ $row['value'] }}</span>
@endif
</div>
@if(($row['meta'] ?? null) !== null)
<div class="list-meta">{{ $row['meta'] }}</div>
@endif
</td>
</tr>
@endforeach
</table>
