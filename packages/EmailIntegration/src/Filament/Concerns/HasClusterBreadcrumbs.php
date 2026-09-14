<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Email settings cluster pages render their own header (heading, actions)
 * from `<x-email-integration::cluster-header />` at the top of the page view, so it
 * sits with the page content under the cluster tabs. Each page blanks `$heading` so the
 * stock full-width header is not rendered. The app panel disables breadcrumbs globally
 * (AppPanelProvider::breadcrumbs(false)); pages that still need a trail opt in via
 * shouldRenderClusterBreadcrumbs().
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

    public function shouldRenderClusterBreadcrumbs(): bool
    {
        return true;
    }
}
