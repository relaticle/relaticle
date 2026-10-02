<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as DbBuilder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class StaleDaysFilter implements Filter
{
    private const int MAX_DAYS = 3650;

    public function __construct(private User $user) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $days = is_array($value) && array_keys($value) === ['$gte'] ? Operand::integer($value['$gte']) : null;

        if ($days === null || $days < 1 || $days > self::MAX_DAYS) {
            throw FilterErrors::at('', __('validation.filter.stale_days', ['max' => self::MAX_DAYS]));
        }

        $workspaceId = $this->user->currentWorkspace->getKey();

        $query->whereNotExists(fn (DbBuilder $activity) => $activity->from('activity_log')
            ->where('activity_log.workspace_id', $workspaceId)
            ->where('activity_log.subject_type', 'opportunity')
            ->whereColumn('activity_log.subject_id', 'opportunities.id')
            ->where('activity_log.created_at', '>=', now()->subDays($days)));
    }
}
