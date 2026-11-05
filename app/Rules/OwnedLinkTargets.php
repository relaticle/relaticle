<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use App\Models\Scopes\WorkspaceScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Translation\PotentiallyTranslatedString;
use Relaticle\CustomFields\Data\RecordLinkPayload;

// The package only checks that a target exists, so this stops one workspace linking another's records by id.
final readonly class OwnedLinkTargets implements ValidationRule
{
    public function __construct(
        private CustomField $field,
        private string $tenantId,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $definition = $this->field->relationshipDefinition();

        if (! $definition instanceof CustomFieldRelationship) {
            return;
        }

        $entityType = $definition->farEnd($this->field)->entityType;
        $modelClass = Relation::getMorphedModel($entityType);

        if (! CrmEntity::tryFrom($entityType) instanceof CrmEntity
            || $modelClass === null
            || ! is_subclass_of($modelClass, Model::class)) {
            $fail(__('validation.custom_field.unsupported_lookup', [
                'field' => $this->field->name,
                'type' => $entityType,
            ]));

            return;
        }

        // RecordLinkPayload drops anything that is not an id, so a nested array would
        // pass as an empty list. The raw payload is checked before it is normalised.
        foreach ($this->rawItems($value) as $item) {
            if (! is_string($item) && ! is_int($item)) {
                $fail(__('validation.custom_field.record_ids', ['field' => $this->field->name]));

                return;
            }
        }

        $ids = array_values(array_unique(array_map(strval(...), RecordLinkPayload::fromValue($value)->ids)));

        if ($ids === []) {
            return;
        }

        $query = $modelClass::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereIn((new $modelClass)->getKeyName(), $ids)
            ->where('workspace_id', $this->tenantId);

        $owned = $query->pluck((new $modelClass)->getKeyName())->map(strval(...))->all();
        $missing = array_values(array_diff($ids, $owned));

        if ($missing === []) {
            return;
        }

        $fail(__('validation.custom_field.foreign_records', [
            'field' => $this->field->name,
            'ids' => implode(', ', $missing),
        ]));
    }

    /** @return array<array-key, mixed> */
    private function rawItems(mixed $value): array
    {
        if (is_array($value) && array_key_exists('ids', $value)) {
            $value = $value['ids'];
        }

        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }
}
