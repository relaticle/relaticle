<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Widgets\Overview;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Support\HtmlString;
use Relaticle\Cloud\Admin\Metrics\Cohorts;
use Relaticle\Cloud\Admin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Filament\Support\HelpLabel;

final class CohortTable extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'cloud::filament.widgets.cohort-table';

    /**
     * @return array{heading: HtmlString, rows: list<array{week: CarbonImmutable, size: int, shares: list<int|null>}>}
     */
    protected function getViewData(): array
    {
        $week = now()->startOfWeek(CarbonInterface::MONDAY)->toDateString();

        /** @var list<array{week: CarbonImmutable, size: int, shares: list<int|null>}> $rows */
        $rows = (new OverviewCache)->remember("value.cohorts.{$week}", fn (): array => Cohorts::rows());

        return [
            'heading' => HelpLabel::make('Who comes back', 'Each row is one week of real signups. Each column is the share of them who were active that week: they added a record of their own or typed a chat message. A dot means that week has not happened yet.'),
            'rows' => $rows,
        ];
    }
}
