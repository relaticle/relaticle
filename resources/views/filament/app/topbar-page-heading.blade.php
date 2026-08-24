{{-- The app panel's page title, shown in the topbar rather than in the page body.

     Expects: $heading, and optionally $headingEnd.

     The teleport target lives inside the Topbar Livewire component, and a morph
     of that component removes any child it did not itself render. So anything
     that re-renders the topbar (a tenant switch, a topbar action, a $refresh
     aimed at it) used to delete this node, and because the inline <h1> is
     suppressed for this panel the page was left with no heading at all until a
     full navigation. The hook below only puts the node back; Alpine still owns
     its lifecycle, so wire:navigate cleanup is unchanged.

     One hook for the whole page, not one per render: window.__fiPageHeading
     always points at the current heading, so re-registering per render would
     just leak closures across navigations. --}}
<template x-teleport=".fi-topbar-start">
    <div
        data-page-heading
        class="fi-topbar-page-heading"
        title="{{ str(strip_tags((string) $heading))->squish() }}"
        x-init="
            window.__fiPageHeading = $el;

            if (! window.__fiPageHeadingRehomeBound) {
                window.__fiPageHeadingRehomeBound = true;

                window.Livewire?.hook?.('morphed', () => {
                    const heading = window.__fiPageHeading;
                    const target = document.querySelector('.fi-topbar-start');

                    if (heading && target && heading.parentElement !== target) {
                        target.append(heading);
                    }
                });
            }
        "
    >
        <h1 class="fi-topbar-page-title">{{ $heading }}</h1>

        @if (filled($headingEnd ?? null))
            {{ $headingEnd }}
        @endif
    </div>
</template>
