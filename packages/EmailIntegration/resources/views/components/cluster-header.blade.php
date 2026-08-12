{{-- Breadcrumbs and heading are spaced like the accounts page: the page content gap
     separates them, pulled in slightly so the pair reads as one block. --}}
<x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />

<x-filament-panels::header
    class="-mt-2"
    :actions="$this->clusterHeaderActions()"
    :heading="$this->getTitle()"
/>
