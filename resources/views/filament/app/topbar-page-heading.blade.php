{{-- The app panel's page title, shown in the topbar rather than in the page body.

     Expects: $heading, and optionally $headingEnd.

     The teleport target lives inside the Topbar Livewire component, which is not
     re-rendered by a page navigation and whose own morph removes any child it did
     not itself render. Two things go wrong on their own:

       - a morph of the topbar (tenant switch, topbar action, a $refresh aimed at
         it) deletes this node, and since the inline h1 is suppressed for this
         panel the page is left with no heading at all;
       - a wire:navigate to a page that renders no heading leaves the previous
         page's node sitting in the topbar, so the dashboard wears the title of
         wherever you came from.

     The first is a real defect. The second is what the obvious fix for it
     causes: hold a reference to the node and re-append it, and you defeat the
     cleanup Alpine already does when the source page is destroyed. So the sync
     in the panel layout reads THIS template every time rather than remembering
     anything, and the rule it enforces is simply that the topbar shows the
     heading the current page rendered and nothing otherwise. --}}
<template x-teleport=".fi-topbar-start" data-page-heading-source>
    <div
        data-page-heading
        class="fi-topbar-page-heading"
        title="{{ str(strip_tags((string) $heading))->squish() }}"
    >
        <h1 class="fi-topbar-page-title">{{ $heading }}</h1>

        @if (filled($headingEnd ?? null))
            {{ $headingEnd }}
        @endif
    </div>
</template>
