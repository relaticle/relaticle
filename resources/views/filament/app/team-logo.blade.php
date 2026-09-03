@props(['team'])

@php
    $src = asset('storage/'.$team->logo_path);
@endphp

<span class="sr-only">{{ $team->name }}</span>
<img src="{{ $src }}" alt="{{ $team->name }}" class="h-10 w-auto rounded" />
