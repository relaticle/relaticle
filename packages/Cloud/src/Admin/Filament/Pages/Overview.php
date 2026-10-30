<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\WidgetConfiguration;
use Override;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\CohortTable;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\MoneyStats;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\ProblemsStats;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\SalesLeads;
use Relaticle\Cloud\Admin\Filament\Widgets\Overview\ValueStats;
use Relaticle\Cloud\Admin\Metrics\OverviewCache;

final class Overview extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Overview';

    protected ?string $subheading = 'Four questions, answered every week. Click any number for the list behind it.';

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('cloud::admin.navigation.dashboards');
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('cloud::admin.overview.navigation_label');
    }

    /**
     * @return array<class-string | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            MoneyStats::class,
            ValueStats::class,
            CohortTable::class,
            SalesLeads::class,
            ProblemsStats::class,
        ];
    }

    public function getColumns(): int
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('cloud::admin.overview.refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    (new OverviewCache)->flush();
                    Notification::make()->title(__('cloud::admin.overview.refreshed'))->success()->send();
                    $this->redirect(self::getUrl(), navigate: true);
                }),
        ];
    }
}
