@if ($this->shouldRenderSettingsBreadcrumbs())
    <x-filament::breadcrumbs :breadcrumbs="$this->getBreadcrumbs()" />
@endif

<x-filament-panels::header
    @class(['-mt-2' => $this->shouldRenderSettingsBreadcrumbs()])
    :actions="$this->settingsHeaderActions()"
    :heading="$this->getTitle()"
/>
