<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Contracts\Billing;
use App\Filament\Pages\EditWorkspace;
use App\Filament\Pages\Workspace\ActivityLog;
use App\Filament\Pages\Workspace\CustomFields;
use App\Filament\Pages\Workspace\Members;
use App\Models\Workspace;
use Closure;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;

/**
 * The workspace settings tab strip. Every page it links to uses this concern, so
 * the strip is identical whichever tab you land on.
 *
 * Filament builds sub-navigation from the page class, not from panel config, and
 * hides items whose `visible()` resolves false, so each tab carries the same
 * guard as its page.
 */
trait HasWorkspaceSettingsNavigation
{
    private const string BILLING_SLUG = 'billing';

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    /**
     * @return array<NavigationItem>
     */
    public function getSubNavigation(): array
    {
        /** @var Workspace $tenant */
        $tenant = Filament::getTenant();

        return [
            NavigationItem::make()
                ->label(__('workspaces.tabs.general'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->url(fn (): string => EditWorkspace::getUrl())
                ->isActiveWhen($this->isCurrentPage(EditWorkspace::class))
                ->visible(fn (): bool => EditWorkspace::canView($tenant)),

            NavigationItem::make()
                ->label(__('workspaces.tabs.members'))
                ->icon(Heroicon::OutlinedUsers)
                ->url(fn (): string => Members::getUrl())
                ->isActiveWhen($this->isCurrentPage(Members::class))
                ->visible(fn (): bool => Members::canAccess()),

            NavigationItem::make()
                ->label(__('workspaces.tabs.custom_fields'))
                ->icon(CustomFields::NAVIGATION_ICON)
                ->url(fn (): string => CustomFields::getUrl())
                ->isActiveWhen($this->isCurrentPage(CustomFields::class))
                ->visible(fn (): bool => CustomFields::canAccess()),

            NavigationItem::make()
                ->label(__('workspaces.tabs.email'))
                ->icon(Heroicon::OutlinedEnvelope)
                ->url(fn (): string => EmailAccountsPage::getUrl())
                ->isActiveWhen($this->isCurrentPage(
                    EmailAccountsPage::class,
                    EmailAccountSettingsPage::class,
                    ManageEmailTemplates::class,
                    EmailPrivacySettingsPage::class,
                    EmailSignaturesPage::class,
                    EmailAccessRequestsPage::class,
                ))
                ->visible(fn (): bool => EmailAccountsPage::canAccess()),

            NavigationItem::make()
                ->label(__('workspaces.tabs.import_history'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(fn (): string => ImportHistory::getUrl())
                ->isActiveWhen($this->isCurrentPage(ImportHistory::class))
                ->visible(fn (): bool => ImportHistory::canAccess()),

            NavigationItem::make()
                ->label(__('workspaces.tabs.activity'))
                ->icon(Heroicon::OutlinedClock)
                ->url(fn (): string => ActivityLog::getUrl())
                ->isActiveWhen($this->isCurrentPage(ActivityLog::class))
                ->visible(fn (): bool => ActivityLog::canAccess()),

            $this->billingNavigationItem($tenant),
        ];
    }

    private function billingNavigationItem(Workspace $tenant): NavigationItem
    {
        $billingUrl = $this->billingUrl($tenant);

        return NavigationItem::make()
            ->label(__('workspaces.tabs.billing'))
            ->icon(Heroicon::OutlinedCreditCard)
            ->url($billingUrl)
            ->isActiveWhen(fn (): bool => static::getSlug() === self::BILLING_SLUG)
            ->visible($billingUrl !== null);
    }

    private function billingUrl(Workspace $tenant): ?string
    {
        return resolve(Billing::class)->url($tenant);
    }

    /**
     * Matching on the rendered page rather than the current route, because every
     * interaction on these pages (a table sort, a modal, switching entity type)
     * re-renders the strip inside a `livewire.update` request, where no page route
     * is current and every tab would come back unhighlighted.
     *
     * @param  class-string  ...$pages
     * @return Closure(): bool
     */
    private function isCurrentPage(string ...$pages): Closure
    {
        return fn (): bool => in_array(static::class, $pages, true);
    }
}
