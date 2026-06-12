<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Date;
use Relaticle\Chat\Models\ChatMessageFeedback;

final class ChatFeedbackStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $since = Date::now()->subDays(30);

        $total = ChatMessageFeedback::query()->where('created_at', '>=', $since)->count();
        $down = ChatMessageFeedback::query()
            ->where('created_at', '>=', $since)
            ->where('rating', ChatMessageFeedback::RATING_DOWN)
            ->count();

        $downRate = $total > 0 ? round($down / $total * 100) : 0;

        return [
            Stat::make('Ratings (30d)', number_format($total)),
            Stat::make('Thumbs down (30d)', number_format($down)),
            Stat::make('Down rate (30d)', "{$downRate}%")
                ->color($downRate > 20 ? 'danger' : ($downRate > 10 ? 'warning' : 'success')),
        ];
    }
}
