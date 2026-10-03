<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Relaticle\SystemAdmin\Metrics\Scopes\GenuineSignup;

final readonly class Cohorts
{
    private const int WEEKS = 6;

    private const int FOLLOW_UP_WEEKS = 4;

    /**
     * @return list<array{week: CarbonImmutable, size: int, shares: list<int|null>}>
     */
    public static function rows(): array
    {
        $currentWeek = now()->startOfWeek(CarbonInterface::MONDAY);
        $firstWeek = $currentWeek->subWeeks(self::WEEKS);

        $signups = User::query()
            ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
            ->where('users.created_at', '>=', $firstWeek)
            ->where('users.created_at', '<', $currentWeek)
            ->get(['users.id', 'users.created_at']);

        $activeWeeks = ActivityDays::from()
            ->whereIn('activity.user_id', $signups->pluck('id')->map(fn (mixed $id): string => (string) $id)->all())
            ->where('activity.day', '>=', $firstWeek->toDateString())
            ->selectRaw("activity.user_id, date_trunc('week', activity.day)::date as week")
            ->distinct()
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $rows): array => $rows->pluck('week')->map(fn (mixed $week): string => (string) $week)->all());

        $rows = [];

        for ($index = 0; $index < self::WEEKS; $index++) {
            $week = $firstWeek->addWeeks($index);
            $members = $signups->filter(fn (User $user): bool => $user->created_at !== null
                && $user->created_at->greaterThanOrEqualTo($week)
                && $user->created_at->lessThan($week->addWeek()));
            $size = $members->count();
            $shares = [];

            for ($offset = 0; $offset < self::FOLLOW_UP_WEEKS; $offset++) {
                $target = $week->addWeeks($offset);

                if ($size === 0 || $target->addWeek()->greaterThan($currentWeek)) {
                    $shares[] = null;

                    continue;
                }

                $active = $members->filter(fn (User $user): bool => in_array($target->toDateString(), $activeWeeks->get((string) $user->id, []), true))->count();
                $shares[] = (int) round($active / $size * 100);
            }

            $rows[] = ['week' => $week, 'size' => $size, 'shares' => $shares];
        }

        return $rows;
    }
}
