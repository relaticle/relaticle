<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Filament\Pages\EditTeam;
use App\Filament\Pages\Team\ActivityLog;
use App\Filament\Pages\Team\CustomFields;
use App\Filament\Pages\Team\Members;
use App\Models\Team;
use Closure;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Laravel\Pennant\Feature;
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
    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }

    /**
     * @return array<NavigationItem>
     */
    public function getSubNavigation(): array
    {
        /** @var Team $tenant */
        $tenant = Filament::getTenant();

        return [
            NavigationItem::make()
                ->label(__('teams.tabs.general'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->url(fn (): string => EditTeam::getUrl())
                ->isActiveWhen($this->isCurrentPage(EditTeam::class))
                ->visible(fn (): bool => EditTeam::canView($tenant)),

            NavigationItem::make()
                ->label(__('teams.tabs.members'))
                ->icon(Heroicon::OutlinedUsers)
                ->url(fn (): string => Members::getUrl())
                ->isActiveWhen($this->isCurrentPage(Members::class))
                ->visible(fn (): bool => Members::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.custom_fields'))
                ->icon(Heroicon::OutlinedCube)
                ->url(fn (): string => CustomFields::getUrl())
                ->isActiveWhen($this->isCurrentPage(CustomFields::class))
                ->visible(fn (): bool => CustomFields::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.import_history'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(fn (): string => ImportHistory::getUrl())
                ->isActiveWhen($this->isCurrentPage(ImportHistory::class))
                ->visible(fn (): bool => ImportHistory::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.activity'))
                ->icon(Heroicon::OutlinedClock)
                ->url(fn (): string => ActivityLog::getUrl())
                ->isActiveWhen($this->isCurrentPage(ActivityLog::class))
                ->visible(fn (): bool => ActivityLog::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.billing'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->url(fn (): string => Billing::getUrl())
                ->isActiveWhen($this->isCurrentPage(Billing::class))
                ->visible(fn (): bool => Feature::active(BillingFeature::class)),
        ];
    }

    /**
     * Matching on the rendered page rather than the current route, because every
     * interaction on these pages (a table sort, a modal, switching entity type)
     * re-renders the strip inside a `livewire.update` request, where no page route
     * is current and every tab would come back unhighlighted.
     *
     * @param  class-string  $page
     * @return Closure(): bool
     */
    private function isCurrentPage(string $page): Closure
    {
        return fn (): bool => static::class === $page;
    }
}
