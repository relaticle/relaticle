<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\WidgetConfiguration;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\CohortTable;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\MoneyStats;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\ProblemsStats;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\SalesLeads;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\ValueStats;
use Relaticle\SystemAdmin\Metrics\OverviewCache;

final class Overview extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static string|\UnitEnum|null $navigationGroup = 'Dashboards';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected ?string $subheading = 'Four questions, answered every week. Click any number for the list behind it.';

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
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    (new OverviewCache)->flush();
                    Notification::make()->title('Numbers refreshed')->success()->send();
                    $this->redirect(self::getUrl(), navigate: true);
                }),
        ];
    }
}
