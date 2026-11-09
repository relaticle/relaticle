@props(['topic', 'class' => 'h-5 w-5'])

@php
    /**
     * Keyed by the content path a category or page already owns, so a new
     * section picks up the fallback instead of rendering nothing.
     */
    $icon = match ((string) $topic) {
        'help/getting-started' => 'lucide-rocket',
        'docs/guides' => 'lucide-code',
        'help/import' => 'lucide-upload',
        'docs/guides/contributing' => 'lucide-git-pull-request',
        'docs/guides/self-hosting' => 'lucide-server',
        'docs/guides/mcp' => 'lucide-cpu',
        'api-reference' => 'lucide-square-terminal',
        default => 'lucide-book-open',
    };
@endphp

<x-dynamic-component :component="$icon" {{ $attributes->class($class) }} />
