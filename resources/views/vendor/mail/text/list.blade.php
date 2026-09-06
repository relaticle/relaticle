@props(['title' => null, 'rows' => []])
@if($title !== null)
{{ $title }}
@endif
@foreach($rows as $row)
@php
    $line = $row['label'];

    if (($row['value'] ?? null) !== null) {
        $line .= ': '.$row['value'];
    }

    if (($row['url'] ?? null) !== null) {
        $line .= ($row['value'] ?? null) === null ? ': '.$row['url'] : ' ('.$row['url'].')';
    }

    if (($row['meta'] ?? null) !== null) {
        $line .= ' ('.$row['meta'].')';
    }
@endphp
{{ $line }}

@endforeach
