<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Filament\Actions\Action;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;

/**
 * Email settings pages render their own header (heading, actions) from
 * `<x-email-integration::settings-header />` at the top of the page view, so it sits
 * with the page content under the workspace settings tabs. Each page blanks `$heading`
 * so the stock full-width header is not rendered. The app panel disables breadcrumbs
 * globally (AppPanelProvider::breadcrumbs(false)); pages that still need a trail opt in
 * via shouldRenderSettingsBreadcrumbs().
 */
trait HasEmailSettingsHeader
{
    /**
     * Actions for the settings header. They cannot be registered as page header actions:
     * that alone makes Filament render the stock full-width header.
     *
     * @return array<int, Action>
     */
    public function settingsHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            EmailAccountsPage::getUrl() => (string) __('workspaces.tabs.email'),
            static::getNavigationLabel(),
        ];
    }

    public function shouldRenderSettingsBreadcrumbs(): bool
    {
        return true;
    }
}
