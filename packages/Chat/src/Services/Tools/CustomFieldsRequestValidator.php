<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Relaticle\CustomFields\Facades\CustomFieldsType;

final readonly class CustomFieldsRequestValidator
{
    /**
     * Validate the LLM-submitted custom_fields payload for the given entity.
     *
     * Translates option labels into option IDs for choice fields, then runs
     * the same `ValidCustomFields` rule the MCP tools use. Returns a result
     * with either a clean payload (keys by code, values normalized for the
     * action layer) or an error string suitable for tool output.
     */
    public function validate(User $user, string $entityType, mixed $rawCustomFields): CustomFieldsValidationResult
    {
        if (! is_array($rawCustomFields) || $rawCustomFields === []) {
            return new CustomFieldsValidationResult(cleanFields: [], error: null);
        }

        $teamId = $user->currentTeam->getKey();

        $fields = $this->loadFields($teamId, $entityType, array_keys($rawCustomFields));

        $translated = $this->translateLabels($rawCustomFields, $fields);

        if ($translated->error !== null) {
            return $translated;
        }

        $rules = new ValidCustomFields($teamId, $entityType, isUpdate: true)
            ->toRules($translated->cleanFields);

        $validator = Validator::make(['custom_fields' => $translated->cleanFields], $rules);

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

        foreach ($raw as $code => $value) {
            $field = $byCode->get($code);

            if (! $field instanceof CustomField) {
                $clean[$code] = $value;

                continue;
            }

            $typeData = CustomFieldsType::getFieldType($field->type);
            $dataType = $typeData?->dataType;

            if ($dataType === null || ! $dataType->isChoiceField()) {
                $clean[$code] = $value;

                continue;
            }

            if ($typeData->acceptsArbitraryValues || $field->lookup_type !== null) {
                $clean[$code] = $value;

                continue;
            }

            $optionsByLabel = $field->options->keyBy('name');

            if ($dataType->isMultiChoiceField()) {
                if (! is_array($value)) {
                    return new CustomFieldsValidationResult(
                        cleanFields: [],
                        error: "custom_fields.{$code} must be an array of option labels.",
                    );
                }

                $translated = [];
                foreach ($value as $label) {
                    $option = $optionsByLabel->get((string) $label);
                    if ($option === null) {
                        return new CustomFieldsValidationResult(
                            cleanFields: [],
                            error: "custom_fields.{$code} option \"{$label}\" is not one of the configured choices.",
                        );
                    }
                    $translated[] = $option->id;
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

            $option = $optionsByLabel->get((string) $value);
            if ($option === null) {
                return new CustomFieldsValidationResult(
                    cleanFields: [],
                    error: "custom_fields.{$code} option \"{$value}\" is not one of the configured choices.",
                );
            }

            $clean[$code] = $option->id;
        }

        return new CustomFieldsValidationResult(cleanFields: $clean, error: null);
    }
}
