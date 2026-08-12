<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Email settings cluster pages render their own header (breadcrumbs, heading, actions)
 * from `<x-email-integration::cluster-header />` at the top of the page view, so it
 * starts at the content column like the accounts page rather than spanning the cluster
 * navigation. Each page blanks `$heading` so the stock full-width header is not
 * rendered at all — it would drop the breadcrumbs anyway, since the app panel disables
 * them globally (AppPanelProvider::breadcrumbs(false)).
 */
trait HasClusterBreadcrumbs
{
    /**
     * Actions for the cluster header. They cannot be registered as page header actions:
     * that alone makes Filament render the stock full-width header.
     *
     * @return array<int, Action>
     */
    public function clusterHeaderActions(): array
    {
        return [];
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
