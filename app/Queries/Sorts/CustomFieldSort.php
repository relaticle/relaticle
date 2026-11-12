<?php

declare(strict_types=1);

namespace App\Queries\Sorts;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * @implements Sort<Model>
 */
final readonly class CustomFieldSort implements Sort
{
    public function __construct(private CustomField $field) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $model = $query->getModel();

        $column = $this->field->getValueColumn();
        $value = CustomFieldValue::query()
            ->whereColumn('entity_id', $model->getTable().'.id')
            ->where('entity_type', $model->getMorphClass())
            ->where('custom_field_id', $this->field->getKey())
            ->limit(1);

        // Postgres sorts null first when descending, so records holding a value are ranked ahead.
        if ($descending) {
            $query->orderBy($value->clone()->selectRaw('1')->whereNotNull($column));
        }

        $query->orderBy($value->select($column), $descending ? 'desc' : 'asc');
    }
}
