@props([
    'name',
])

{{-- Relaticle nav icons.

     Drawn in the brand's own grammar, taken from the logomark: filled circles
     as nodes, joined by round-capped strokes as relationships. Every icon
     here is built from those two primitives only, on a 24x24 grid with a
     1.75 stroke, so the set reads as one family and sits beside the logo
     without looking borrowed from a generic icon pack.

     Nodes use `fill="currentColor"`; edges use `stroke="currentColor"`.
     Callers set size and color with utility classes. --}}

@php
    $paths = [
        // Rela: the logomark figure, a hub joined to three satellites. Edges are
        // drawn first so the nodes sit on top of them.
        'assistant' => '<path d="M12 12 6.6 7.4M12 12l6-2.6M12 12l4.4 5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="3.4" fill="currentColor"/>
            <circle cx="5.6" cy="6.6" r="2.2" fill="currentColor"/>
            <circle cx="18.8" cy="8.8" r="1.9" fill="currentColor"/>
            <circle cx="17" cy="17.6" r="2.4" fill="currentColor"/>',

        // Features: a lattice of records. Nodes inset so nothing clips the grid.
        'features' => '<path d="M8.4 7.5h7.2M7.5 8.4v7.2M16.5 8.4v7.2M8.4 16.5h7.2" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="7.5" cy="7.5" r="2.3" fill="currentColor"/>
            <circle cx="16.5" cy="7.5" r="2.3" fill="currentColor"/>
            <circle cx="7.5" cy="16.5" r="2.3" fill="currentColor"/>
            <circle cx="16.5" cy="16.5" r="2.3" fill="currentColor"/>',

        // Self-hosted: one server rack, its nodes stacked on your own metal.
        'self-hosted' => '<rect x="3.5" y="4.5" width="17" height="6" rx="2" stroke="currentColor" stroke-width="1.75"/>
            <rect x="3.5" y="13.5" width="17" height="6" rx="2" stroke="currentColor" stroke-width="1.75"/>
            <circle cx="7.5" cy="7.5" r="1.3" fill="currentColor"/>
            <circle cx="7.5" cy="16.5" r="1.3" fill="currentColor"/>',

        // MCP and API: an outside client node wired into two of ours.
        'api' => '<path d="M7.2 12h9.6M16.8 12l-3.4-3.6M16.8 12l-3.4 3.6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="5.2" cy="12" r="2.4" fill="currentColor"/>
            <circle cx="18.6" cy="6.4" r="1.8" fill="currentColor"/>
            <circle cx="18.6" cy="17.6" r="1.8" fill="currentColor"/>',

        // Help centre: a node asking, an answer arriving.
        'help' => '<circle cx="12" cy="12" r="8.2" stroke="currentColor" stroke-width="1.75"/>
            <path d="M9.9 9.6a2.15 2.15 0 1 1 2.9 2.02c-.55.2-.8.62-.8 1.18v.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="12" cy="16.3" r="1.15" fill="currentColor"/>',

        // Developers: source, in the brand grammar rather than a stock glyph.
        'developers' => '<path d="M8.4 8.2 4.2 12l4.2 3.8M15.6 8.2 19.8 12l-4.2 3.8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="12" cy="12" r="1.6" fill="currentColor"/>',

        // Blog: a published record, its lines trailing off.
        'blog' => '<rect x="4" y="4.5" width="16" height="15" rx="2.5" stroke="currentColor" stroke-width="1.75"/>
            <circle cx="8.2" cy="9" r="1.35" fill="currentColor"/>
            <path d="M11.8 9h4M8 13.4h8M8 16.4h5.4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" opacity="0.65"/>',

        // Compare: two graphs weighed against each other.
        'compare' => '<circle cx="7" cy="8" r="1.9" fill="currentColor"/>
            <circle cx="17" cy="16" r="1.9" fill="currentColor" opacity="0.65"/>
            <path d="M7 10.4v5.2M17 13.6V8.4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="7" cy="17" r="1.3" fill="currentColor" opacity="0.65"/>
            <circle cx="17" cy="7" r="1.3" fill="currentColor"/>',
    ];
@endphp

<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes }}>
    {!! $paths[$name] ?? $paths['features'] !!}
</svg>
