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
        // Blog: a published record, its author node above the line. The last
        // holdout in this set — Rela, Features, Self-hosted, MCP and API, Help
        // center, and Developers moved to hand-picked isocons.app drawings
        // under `components/icons/`.
        'blog' => '<rect x="4.5" y="4.5" width="15" height="15" rx="3" stroke="currentColor" stroke-width="1.75"/>
            <path d="M12.6 9.4h3.1M8.3 14.6h7.4" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
            <circle cx="8.9" cy="9.4" r="1.7" fill="currentColor"/>',
    ];
@endphp

<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes }}>
    {!! $paths[$name] ?? $paths['blog'] !!}
</svg>
