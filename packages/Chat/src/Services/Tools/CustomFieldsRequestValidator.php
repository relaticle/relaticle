<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Validator;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\CustomFields\Data\RecordLinkPayload;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Models\CustomFieldRelationship;

final readonly class CustomFieldsRequestValidator
{
    public function __construct(
        private CustomFieldOptionMap $optionMap,
        private PlanReferenceValidator $planReferences,
    ) {}

    /**
     * Validate the LLM-submitted custom_fields payload for the given entity.
     *
     * Translates option labels into option IDs for choice fields, then runs
     * the same `ValidCustomFields` rule the MCP tools use. Returns a result
     * with either a clean payload (keys by code, values normalized for the
     * action layer) or an error string suitable for tool output.
     */
    /**
     * @param  string|int|null  $ignoreEntityId  the record being updated, excluded from unique-value checks
     */
    public function validate(
        User $user,
        string $entityType,
        mixed $rawCustomFields,
        bool $isUpdate = true,
        string|int|null $ignoreEntityId = null,
        ?string $conversationId = null,
        ?string $turnId = null,
    ): CustomFieldsValidationResult {
        $rawCustomFields = is_array($rawCustomFields) ? $rawCustomFields : [];

        // An update touches only the submitted codes; a create must also satisfy
        // every required field, so it runs the rules even with an empty payload.
        if ($rawCustomFields === [] && $isUpdate) {
            return new CustomFieldsValidationResult(cleanFields: [], error: null);
        }

        $teamId = $user->currentTeam->getKey();

        $fields = $this->loadFields($teamId, $entityType, array_keys($rawCustomFields));

        $translated = $this->translateLabels($rawCustomFields, $fields);

        if ($translated->error !== null) {
            return $translated;
        }

        $referenceError = $this->planReferenceError($user, $translated->cleanFields, $fields, $conversationId, $turnId);

        if ($referenceError !== null) {
            return new CustomFieldsValidationResult(cleanFields: [], error: $referenceError);
        }

        $rules = new ValidCustomFields($teamId, $entityType, isUpdate: $isUpdate, ignoreEntityId: $ignoreEntityId)
            ->toRules($translated->cleanFields);

        // A reference stands for a record no step has created yet, so the field rules,
        // which ask the database what a value points at, are run without it. It is put
        // back into the stored payload: approval resolves it to the real id.
        $validator = Validator::make(['custom_fields' => $this->withoutPlanReferences($translated->cleanFields, $fields)], $rules);

        if ($validator->fails()) {
            return new CustomFieldsValidationResult(
                cleanFields: [],
                error: 'custom_fields validation failed: '.implode('; ', $validator->errors()->all()),
            );
        }

        return new CustomFieldsValidationResult(cleanFields: $translated->cleanFields, error: null);
    }

    /**
     * @param  array<int, string>  $codes
     * @return Collection<int, CustomField>
     */
    private function loadFields(string $teamId, string $entityType, array $codes): Collection
    {
        /** @var Collection<int, CustomField> */
        return CustomField::query()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', $codes)
            ->with('options')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, CustomField>  $fields
     */
    private function translateLabels(array $raw, Collection $fields): CustomFieldsValidationResult
    {
        $clean = [];
        $byCode = $fields->keyBy('code');
        $optionMap = $this->optionMap->fromFields($fields);

        foreach ($raw as $code => $value) {
            $field = $byCode->get($code);

            if (! $field instanceof CustomField) {
                $clean[$code] = $value;

                continue;
            }

            // Clearing is a null write, so null must reach the rule set rather than
            // being rejected here as a malformed option label. A field that really is
            // required then fails on its own `required` rule with a truthful message,
            // instead of the model being told the value can never be unset.
            if ($value === null) {
                $clean[$code] = null;

                continue;
            }

            // A link field's payload is record ids, in either the plain list or the map
            // form that confirms a replacement, and neither is an option label.
            if ($field->relationshipDefinition() instanceof CustomFieldRelationship) {
                $clean[$code] = $value;

                continue;
            }

            $typeData = CustomFieldsType::getFieldType($field->type);
            $dataType = $typeData?->dataType;

            if ($dataType === null || ! $dataType->isChoiceField()) {
                $clean[$code] = $value;

                continue;
            }

            if ($typeData->acceptsArbitraryValues) {
                $clean[$code] = $value;

                continue;
            }

            $entry = $optionMap[(string) $field->code] ?? ['ids' => [], 'labels' => []];

            if ($dataType->isMultiChoiceField()) {
                if (! is_array($value)) {
                    return new CustomFieldsValidationResult(
                        cleanFields: [],
                        error: "custom_fields.{$code} must be an array of option labels.",
                    );
                }

                $translated = [];
                foreach ($value as $label) {
                    $optionId = $this->optionMap->idFor($entry, (string) $label);
                    if ($optionId === null) {
                        return new CustomFieldsValidationResult(
                            cleanFields: [],
                            error: "custom_fields.{$code} option \"{$label}\" is not one of the configured choices.",
                        );
                    }
                    $translated[] = $optionId;
                }

                $clean[$code] = $translated;

                continue;
            }

            if (! is_string($value) && ! is_int($value)) {
                return new CustomFieldsValidationResult(
                    cleanFields: [],
                    error: "custom_fields.{$code} must be a single option label string.",
                );
            }

            $optionId = $this->optionMap->idFor($entry, (string) $value);
            if ($optionId === null) {
                return new CustomFieldsValidationResult(
                    cleanFields: [],
                    error: "custom_fields.{$code} option \"{$value}\" is not one of the configured choices.",
                );
            }

            $clean[$code] = $optionId;
        }

        return new CustomFieldsValidationResult(cleanFields: $clean, error: null);
    }

    /**
     * A link field may hold `$ref:<pending_action_id>` where a record proposed earlier in
     * this turn will be. It is checked exactly as a native foreign key is: same turn, a
     * still-pending create, of the entity this field points at.
     *
     * @param  array<string, mixed>  $fieldsPayload
     * @param  Collection<int, CustomField>  $fields
     */
    private function planReferenceError(User $user, array $fieldsPayload, Collection $fields, ?string $conversationId, ?string $turnId): ?string
    {
        $byCode = $fields->keyBy('code');

        foreach ($fieldsPayload as $code => $value) {
            $field = $byCode->get((string) $code);

            if (! $field instanceof CustomField) {
                continue;
            }

            $definition = $field->relationshipDefinition();

            if (! $definition instanceof CustomFieldRelationship) {
                continue;
            }

            $modelClass = Relation::getMorphedModel($definition->targetEntityTypeFor($field));

            if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
                return "custom_fields.{$code} points at a record type this workspace does not have.";
            }

            foreach ($this->references($value) as $reference) {
                $error = $this->planReferences->error($user, $reference, $modelClass, $conversationId, $turnId);

                if ($error !== null) {
                    return "custom_fields.{$code}: {$error}";
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fieldsPayload
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, mixed>
     */
    private function withoutPlanReferences(array $fieldsPayload, Collection $fields): array
    {
        $byCode = $fields->keyBy('code');

        foreach ($fieldsPayload as $code => $value) {
            $field = $byCode->get((string) $code);

            if (! $field instanceof CustomField || ! $field->relationshipDefinition() instanceof CustomFieldRelationship) {
                continue;
            }

            if ($this->references($value) === []) {
                continue;
            }

            $kept = array_values(array_filter(
                RecordLinkPayload::fromValue($value)->ids,
                static fn (mixed $id): bool => ! PlanReference::is($id),
            ));

            $fieldsPayload[$code] = $kept;
        }

        return $fieldsPayload;
    }

    /**
     * @return list<string>
     */
    private function references(mixed $value): array
    {
        return array_values(array_filter(
            array_map(strval(...), RecordLinkPayload::fromValue($value)->ids),
            PlanReference::is(...),
        ));
    }
}
