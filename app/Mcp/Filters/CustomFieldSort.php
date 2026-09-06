<?php

declare(strict_types=1);

namespace App\Mcp\Filters;

use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\QueryBuilders\RecordLinkQuery;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * @implements Sort<Model>
 */
final readonly class CustomFieldSort implements Sort
{
    public function __construct(
        private string $entityType,
    ) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $field = $this->resolveField($property);

        if (! $field instanceof CustomField) {
            return;
        }

        $definition = $field->relationshipDefinition();

        if ($definition instanceof CustomFieldRelationship) {
            // A link field has no value row to order by: the sort reads the name of the
            // first record it points at, and unlinked rows sort last either way.
            $entityType = $definition->targetEntityTypeFor($field);

            resolve(RecordLinkQuery::class)->orderByLinkedAttribute(
                $query,
                $definition,
                $definition->readDirectionFor($field),
                CrmEntity::tryFrom($entityType)?->titleColumn() ?? 'name',
                $descending ? 'desc' : 'asc',
            );

            return;
        }

        $valueColumn = CustomFieldValue::getValueColumn($field->type);
        $model = $query->getModel();

        $query->orderBy(
            CustomFieldValue::query()
                ->select($valueColumn)
                ->whereColumn('entity_id', $model->getTable().'.id')
                ->where('entity_type', $model->getMorphClass())
                ->where('custom_field_id', $field->getKey())
                ->limit(1),
            $descending ? 'desc' : 'asc',
        );
    }

    private function resolveField(string $code): ?CustomField
    {
        return $this->resolveAllFields()->get($code);
    }

    /**
     * @return Collection<string, CustomField>
     */
    private function resolveAllFields(): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        /** @var Collection<string, CustomField> */
        return Cache::remember(
            "custom_fields_sort_{$teamId}_{$this->entityType}",
            60,
            fn () => CustomField::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $teamId)
                ->where('entity_type', $this->entityType)
                ->active()
                ->get()
                ->keyBy('code')
        );
    }
}
