<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Illuminate\Contracts\View\View;

/**
 * Email settings cluster pages render their own header (breadcrumbs, heading, actions)
 * from the `cluster-header` partial at the top of the page view, so it starts at the
 * content column like the accounts page rather than spanning the cluster navigation.
 * The stock full-width header is suppressed here, and it would drop the breadcrumbs
 * anyway — the app panel disables them globally (AppPanelProvider::breadcrumbs(false)).
 */
trait HasClusterBreadcrumbs
{
    public function getHeader(): ?View
    {
        return view('email-integration::filament.pages.partials.no-header');
    }

    /**
     * The cluster crumb comes from Filament (Page::getBreadcrumbs); append the page itself.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [...parent::getBreadcrumbs(), static::getNavigationLabel()];
    }
}
