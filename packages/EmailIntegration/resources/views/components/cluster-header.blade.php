@if ($this->shouldRenderClusterBreadcrumbs())
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />
@endif

<x-filament-panels::header
    @class(['-mt-2' => $this->shouldRenderClusterBreadcrumbs()])
    :actions="$this->clusterHeaderActions()"
    :heading="$this->getTitle()"
/>
