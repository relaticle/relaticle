<?php

declare(strict_types=1);

namespace App\Queries\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Scopes\WorkspaceScope;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class EntitiesByFieldValueQuery
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<int, string>  $values
     * @return Collection<int, TModel>
     */
    public function get(string $modelClass, CustomField $field, array $values, int $limit): Collection
    {
        $column = $field->getValueColumn();
        $patterns = array_values(array_map(LikePattern::escape(...), array_filter($values, filled(...))));

        if ($patterns === [] || ! in_array($column, ['string_value', 'text_value', 'json_value'], true)) {
            return new Collection;
        }

        $model = new $modelClass;

        $entityIds = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->select('entity_id')
            ->where((string) config('custom-fields.database.column_names.tenant_foreign_key'), $field->tenant_id)
            ->where('entity_type', $model->getMorphClass())
            ->where('custom_field_id', $field->getKey());

        if ($column === 'json_value') {
            $anyElementMatches = implode(' or ', array_fill(0, count($patterns), 'element.value ilike ?'));

            // A value saved before the field became multi-value can still be a bare scalar.
            $entityIds->whereRaw(
                "exists (select 1 from jsonb_array_elements_text(case when jsonb_typeof(json_value::jsonb) = 'array' then json_value::jsonb else jsonb_build_array(json_value::jsonb) end) as element(value) where {$anyElementMatches})",
                $patterns,
            );
        } else {
            $entityIds->where(function (Builder $query) use ($column, $patterns): void {
                foreach ($patterns as $pattern) {
                    $query->orWhereLike($column, $pattern);
                }
            });
        }

        return $modelClass::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $field->tenant_id)
            ->whereIn($model->getKeyName(), $entityIds)
            ->oldest()
            ->orderBy($model->getKeyName())
            ->limit($limit)
            ->get();
    }
}
