<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Widgets\WidgetConfiguration;
use Relaticle\SystemAdmin\Filament\Widgets\ActivationRateWidget;
use Relaticle\SystemAdmin\Filament\Widgets\UserRetentionChartWidget;

final class EngagementDashboard extends BaseDashboard
{
    use HasFiltersAction;

    protected static string $routePath = 'engagement';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected ?string $heading = 'User Engagement';

    protected ?string $subheading = 'Activation and retention metrics.';

    protected static ?string $navigationLabel = 'Engagement';

    protected static ?int $navigationSort = 1;

    /**
     * @return array<class-string | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            ActivationRateWidget::class,
            UserRetentionChartWidget::class,
        ];
    }

    public function getColumns(): array
    {
        return [
            'default' => 1,
            'lg' => 3,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->schema([
                    Select::make('period')
                        ->label('Time Period')
                        ->options([
                            '7' => 'Last 7 days',
                            '30' => 'Last 30 days',
                            '90' => 'Last 90 days',
                            '365' => 'Last 12 months',
                        ])
                        ->default('30'),
                ])
                ->slideOver(false),
        ];
    }
}
