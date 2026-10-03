<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class AssignedToMeFilter implements Filter
{
    public function __construct(private User $user) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $assigned = is_array($value) && array_keys($value) === ['$eq'] && Operand::boolean($value['$eq']) === true;

        if (! $assigned) {
            throw FilterErrors::at('', __('validation.filter.assigned_to_me'));
        }

        $query->whereHas('assignees', fn (Builder $q): Builder => $q->whereKey($this->user->getKey()));
    }
}
