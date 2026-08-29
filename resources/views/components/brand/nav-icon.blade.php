@props([
    'name',
])

{{-- Relaticle nav icons.

     Drawn in the brand's own grammar, taken from the logomark: filled circles
     as nodes, joined by round-capped strokes as relationships.

     The set holds to one geometry so it reads as a family at the 20px the mega
     menu renders it at:

     - 24x24 grid, every figure centred on (12,12) and sized to a ~15-16 unit
       bounding box, so no icon looks larger than its neighbour in the same
       column.
     - One stroke weight, 1.75, round cap and join.
     - Two node sizes: 2.3-2.4 for a primary node, 1.6-1.7 for a secondary one.
       The assistant hub is the single exception, because it carries the
       logomark's own proportions.
     - Full opacity throughout. Half-opacity strokes silt up at this size,
       especially in dark mode.

     Edges are declared before nodes so the nodes paint over the stroke ends,
     which is what keeps the joins clean without hand-tuned gaps.

     Nodes use `fill="currentColor"`; edges use `stroke="currentColor"`.
     Callers set size and color with utility classes. --}}

@php
    $paths = [
        // Rela: the logomark's own figure, a hub and three satellites held at
        // the master artwork's angles (up-left, up-right, down-right) so it
        // reads as the same mark as the header lockup. The radii are pulled in
        // from the logo's proportions on purpose: at 20px the logo's own short
        // edges disappear under the circles and the mark silts into a blob,
        // where the logo survives it on gradient and size.
        'assistant' => '<path d="M12 12 6.25 7.67M12 12l6.13-3.77M12 12l5.57 4.56" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="12" cy="12" r="3" fill="currentColor"/>
            <circle cx="6.25" cy="7.67" r="1.9" fill="currentColor"/>
            <circle cx="18.13" cy="8.23" r="1.5" fill="currentColor"/>
            <circle cx="17.57" cy="16.56" r="2" fill="currentColor"/>',

        // Features: a lattice of records, four nodes on a closed circuit.
        'features' => '<path d="M6.5 6.5h11v11h-11z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/>
            <circle cx="6.5" cy="6.5" r="2" fill="currentColor"/>
            <circle cx="17.5" cy="6.5" r="2" fill="currentColor"/>
            <circle cx="6.5" cy="17.5" r="2" fill="currentColor"/>
            <circle cx="17.5" cy="17.5" r="2" fill="currentColor"/>',

        // Self-hosted: a two-unit rack, its status nodes on your own metal.
        'self-hosted' => '<rect x="4.5" y="4.75" width="15" height="6" rx="2" stroke="currentColor" stroke-width="1.75"/>
            <rect x="4.5" y="13.25" width="15" height="6" rx="2" stroke="currentColor" stroke-width="1.75"/>
            <circle cx="8.2" cy="7.75" r="1.6" fill="currentColor"/>
            <circle cx="8.2" cy="16.25" r="1.6" fill="currentColor"/>',

        // MCP and API: our record and your system either side of an interface.
        // Deliberately a flat horizontal pair, so it cannot be mistaken for the
        // radial assistant mark two rows above it. The hollow circle is the
        // only one in the set: hollow is yours, filled is ours, and the break
        // in the edge is the contract between them.
        'api' => '<path d="M7.9 12h3.1M13 12h2.7" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="5.6" cy="12" r="2" fill="currentColor"/>
            <circle cx="17.6" cy="12" r="2" stroke="currentColor" stroke-width="1.75"/>',

        // Help center: a node asking, and the answer it is waiting on.
        'help' => '<circle cx="12" cy="12" r="7.6" stroke="currentColor" stroke-width="1.75"/>
            <path d="M9.9 9.7a2.15 2.15 0 1 1 2.9 2.02c-.55.2-.8.62-.8 1.18v.4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="12" cy="16.1" r="1.3" fill="currentColor"/>',

        // Developers: source, in the brand grammar rather than a stock glyph.
        'developers' => '<path d="M8.6 8.2 4.6 12l4 3.8M15.4 8.2l4 3.8-4 3.8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="12" cy="12" r="2" fill="currentColor"/>',

        // Blog: a published record, its author node above the line.
        'blog' => '<rect x="4.5" y="4.5" width="15" height="15" rx="3" stroke="currentColor" stroke-width="1.75"/>
            <path d="M12.6 9.4h3.1M8.3 14.6h7.4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="8.9" cy="9.4" r="1.7" fill="currentColor"/>',
    ];
@endphp

<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes }}>
    {!! $paths[$name] ?? $paths['features'] !!}
</svg>
