<?php

declare(strict_types=1);

namespace App\Queries\Sorts;

use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\QueryBuilders\RecordLinkQuery;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * @implements Sort<Model>
 */
final readonly class CustomFieldSort implements Sort
{
    public function __construct(private CustomField $field) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $definition = $this->field->relationshipDefinition();

        if ($definition instanceof CustomFieldRelationship) {
            // A link field has no value row to order by: the sort reads the name of the
            // first record it points at, and unlinked rows sort last either way.
            resolve(RecordLinkQuery::class)->orderByLinkedAttribute(
                $query,
                $definition,
                $definition->readDirectionFor($this->field),
                CrmEntity::tryFrom($definition->targetEntityTypeFor($this->field))?->titleColumn() ?? 'name',
                $descending ? 'desc' : 'asc',
            );

            return;
        }

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
