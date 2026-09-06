<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\CrmEntity;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Translation\PotentiallyTranslatedString;
use Relaticle\CustomFields\Data\RecordLinkPayload;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

/**
 * Every record a link field points at has to belong to the workspace writing it.
 *
 * The package resolves a target through the model's own query, and Relaticle's CRM
 * models carry no global team scope, so nothing below this stops one workspace from
 * linking another's company by id. It runs wherever custom fields are validated: the
 * API, the MCP tools, and the chat write tools.
 */
final readonly class OwnedLinkTargets implements ValidationRule
{
    public function __construct(
        private BaseCustomField $field,
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

        $entityType = $definition->targetEntityTypeFor($this->field);
        $modelClass = Relation::getMorphedModel($entityType);

        if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
            $fail(__('The :attribute field points at a record type this workspace does not have.'))->translate();

            return;
        }

        $ids = array_values(array_unique(array_map(strval(...), RecordLinkPayload::fromValue($value)->ids)));

        if ($ids === []) {
            return;
        }

        $query = $modelClass::query()->whereIn((new $modelClass)->getKeyName(), $ids);

        // Only the CRM entities carry a team column. A target outside them is checked for
        // existence alone rather than against a column that is not there.
        if (CrmEntity::tryFrom($entityType) instanceof CrmEntity) {
            $query->where('team_id', $this->tenantId);
        }

        if ($query->count() === count($ids)) {
            return;
        }

        $fail(__('The :attribute field references a record that is not in this workspace.'))->translate();
    }
}
