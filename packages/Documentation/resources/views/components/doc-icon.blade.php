@props(['topic', 'class' => 'h-5 w-5'])

@php
    /**
     * Keyed by the content path a category or page already owns, so a new
     * section picks up the fallback instead of rendering nothing.
     */
    $icon = match ((string) $topic) {
        'help/getting-started' => 'ri-rocket-line',
        'docs/guides' => 'ri-code-s-slash-line',
        'docs/guides/getting-started' => 'ri-rocket-line',
        'docs/guides/import' => 'ri-upload-2-line',
        'docs/guides/developer' => 'ri-code-s-slash-line',
        'docs/guides/self-hosting' => 'ri-server-line',
        'docs/guides/mcp' => 'ri-cpu-line',
        'api-reference' => 'ri-terminal-box-line',
        default => 'ri-book-open-line',
    };
@endphp

<x-dynamic-component :component="$icon" {{ $attributes->class($class) }} />
