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
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;
use Laravel\Pennant\Feature;

/**
 * The workspace settings tab strip. Every page it links to uses this concern, so
 * the strip is identical whichever tab you land on.
 *
 * Filament builds sub-navigation from the page class, not from panel config, and
 * hides items whose `visible()` resolves false — so each tab carries the same
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
                ->isActiveWhen(fn (): bool => request()->routeIs(EditTeam::getRouteName()))
                ->visible(fn (): bool => EditTeam::canView($tenant)),

            NavigationItem::make()
                ->label(__('teams.tabs.members'))
                ->icon(Heroicon::OutlinedUsers)
                ->url(fn (): string => Members::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs(Members::getRouteName()))
                ->visible(fn (): bool => Members::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.custom_fields'))
                ->icon(Heroicon::OutlinedCube)
                ->url(fn (): string => CustomFields::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs(CustomFields::getRouteName()))
                ->visible(fn (): bool => CustomFields::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.activity'))
                ->icon(Heroicon::OutlinedClock)
                ->url(fn (): string => ActivityLog::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs(ActivityLog::getRouteName()))
                ->visible(fn (): bool => ActivityLog::canAccess()),

            NavigationItem::make()
                ->label(__('teams.tabs.billing'))
                ->icon(Heroicon::OutlinedCreditCard)
                ->url(fn (): string => Billing::getUrl())
                ->isActiveWhen(fn (): bool => request()->routeIs(Billing::getRouteName()))
                ->visible(fn (): bool => Feature::active(BillingFeature::class)),
        ];
    }
}
