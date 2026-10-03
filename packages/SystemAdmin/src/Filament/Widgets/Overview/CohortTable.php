<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Support\HtmlString;
use Relaticle\SystemAdmin\Filament\Support\HelpLabel;
use Relaticle\SystemAdmin\Metrics\Cohorts;
use Relaticle\SystemAdmin\Metrics\OverviewCache;

final class CohortTable extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'system-admin::filament.widgets.cohort-table';

    /**
     * @return array{heading: HtmlString, rows: list<array{week: CarbonImmutable, size: int, shares: list<int|null>}>}
     */
    protected function getViewData(): array
    {
        /** @var list<array{week: CarbonImmutable, size: int, shares: list<int|null>}> $rows */
        $week = now()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $rows = (new OverviewCache)->remember("value.cohorts.{$week}", fn (): array => Cohorts::rows());

        return [
            'heading' => HelpLabel::make('Who comes back', 'Each row is one week of real signups. Each column is the share of them who were active that week: they added a record of their own or typed a chat message. A dot means that week has not happened yet.'),
            'rows' => $rows,
        ];
    }
}
