<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;

final class Dashboard extends BaseDashboard
{
    use HasFiltersAction;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected ?string $heading = 'Relaticle Admin';

    protected ?string $subheading = 'Platform growth and adoption metrics.';

    protected static ?string $navigationLabel = 'Dashboard';

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
            Action::make('view-site')
                ->label('View Website')
                ->url(config('app.url'))
                ->icon('heroicon-o-globe-alt')
                ->color('gray')
                ->openUrlInNewTab(),
        ];
    }
}
