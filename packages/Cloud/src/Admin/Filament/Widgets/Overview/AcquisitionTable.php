<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Widgets\Overview;

use Filament\Widgets\Widget;
use Relaticle\Cloud\Admin\Metrics\AcquisitionFunnel;
use Relaticle\Cloud\Admin\Metrics\OverviewCache;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;

final class AcquisitionTable extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'cloud::filament.widgets.acquisition-table';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'rows' => (new OverviewCache)->remember('acquisition.rows.'.now()->toDateString(), fn (): array => AcquisitionFunnel::rows()),
            'emailEnabled' => EmailIntegrationServiceProvider::enabled(),
        ];
    }
}
