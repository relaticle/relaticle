<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Support\Facades\Validator;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\FieldTypeSystem\FieldManager;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\ImportWizard\Data\InferenceResult;

/**
 * Infers data types from sample values using the custom-fields system.
 *
 * Detection rules and field type mappings are built dynamically
 * from FieldManager - no hardcoded mappings.
 */
final class DataTypeInferencer
{
    /** @var array<string, string> Validation rule key => field type key */
    private array $validationToFieldType = [];

    /** @var array<string, string> FieldDataType value => field type key */
    private array $dataTypeToFieldType = [];

    private bool $initialized = false;

    public function __construct(
        private readonly ?string $entityName = null,
        private readonly ?string $teamId = null,
    ) {}

    /**
     * Analyze sample values and return inference result.
     *
     * @param  array<string>  $values  Sample values from CSV column
     */
    public function infer(array $values): InferenceResult
    {
        $this->initialize();

        $nonEmptyValues = array_filter($values, fn (string $v): bool => $v !== '');

        if ($nonEmptyValues === []) {
            return new InferenceResult(type: null, confidence: 0.0, suggestedFields: []);
        }

        $allTypes = array_merge(
            array_values($this->validationToFieldType),
            array_values($this->dataTypeToFieldType),
            ['text']
        );
        $typeVotes = array_fill_keys(array_unique($allTypes), 0);

        foreach ($nonEmptyValues as $value) {
            $type = $this->detectType(trim((string) $value));
            if (isset($typeVotes[$type])) {
                $typeVotes[$type]++;
            }
        }

        $totalVotes = count($nonEmptyValues);
        arsort($typeVotes);
        $topType = array_key_first($typeVotes);
        $topVotes = $typeVotes[$topType];

        if ($topType === 'text') {
            return new InferenceResult(type: null, confidence: 0.0, suggestedFields: []);
        }

        $confidence = $topVotes / $totalVotes;

        if ($confidence < 0.5) {
            return new InferenceResult(type: null, confidence: $confidence, suggestedFields: []);
        }

        return new InferenceResult(
            type: $topType,
            confidence: $confidence,
            suggestedFields: $this->getSuggestedFieldsForType($topType),
        );
    }

    /**
     * Build detection rules dynamically from FieldManager.
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $fieldManager = resolve(FieldManager::class);

        foreach ($fieldManager->getFieldTypes() as $fieldTypeClass) {
            /** @var \Relaticle\CustomFields\Contracts\FieldTypeDefinitionInterface $instance */
            $instance = new $fieldTypeClass;
            $schema = $instance->configure();
            $data = $schema->data();

            // For multi-value fields with item validation rules
            $itemRules = $schema->getDefaultItemValidationRules();
            if ($itemRules !== []) {
                // Look through all rules for a meaningful validation key
                foreach ($itemRules as $rule) {
                    $validationKey = $this->extractValidationKey($rule);
                    if ($validationKey !== null) {
                        $this->validationToFieldType[$validationKey] = $data->key;
                        break;
                    }
                }
            }

            // Map dataType to field type key
            $this->dataTypeToFieldType[$data->dataType->value] = $data->key;
        }

        $this->initialized = true;
    }

    /**
     * Extract the validation key from a rule.
     * 'email' => 'email', 'phone:AUTO' => 'phone', 'regex:...' => 'link'
     */
    private function extractValidationKey(string $rule): ?string
    {
        // Handle regex rules specially - they're for link validation
        if (str_starts_with($rule, 'regex:')) {
            return 'link';
        }

        // Strip parameters: 'phone:AUTO' => 'phone'
        $key = explode(':', $rule)[0];

        // Only return validation keys we can actually use
        return in_array($key, ['email', 'phone', 'url', 'date'], true) ? $key : null;
    }

    /**
     * Detect the type of a single value.
     */
    private function detectType(string $value): string
    {
        // Check validation-based types (email, phone, url/link)
        foreach ($this->validationToFieldType as $validationKey => $fieldTypeKey) {
            if ($this->passesValidation($value, $validationKey)) {
                return $fieldTypeKey;
            }
        }

        // Check date (using Laravel's date validator)
        if (Validator::make(['v' => $value], ['v' => ['date']])->passes()) {
            return $this->dataTypeToFieldType[FieldDataType::DATE->value] ?? 'date';
        }

        // Check currency (values with currency symbols)
        if ($this->isCurrency($value)) {
            return $this->dataTypeToFieldType[FieldDataType::FLOAT->value] ?? 'currency';
        }

        // Check number
        if ($this->isNumber($value)) {
            return $this->dataTypeToFieldType[FieldDataType::NUMERIC->value] ?? 'number';
        }

        return 'text';
    }

    /**
     * Check if a value passes a specific validation rule.
     */
    private function passesValidation(string $value, string $validationKey): bool
    {
        $rules = match ($validationKey) {
            'email' => ['email'],
            'phone' => ['phone:AUTO'],
            'url', 'link' => ['url'],
            default => null,
        };

        if ($rules === null) {
            return false;
        }

        return Validator::make(['v' => $value], ['v' => $rules])->passes();
    }

    private function isCurrency(string $value): bool
    {
        return (bool) preg_match('/^[$\xe2\x82\xac\xc2\xa3\xc2\xa5]\s*[\d,]+(\.\d{1,2})?$|^[\d,]+(\.\d{1,2})?\s*[$\xe2\x82\xac\xc2\xa3\xc2\xa5]$/u', $value);
    }

    private function isNumber(string $value): bool
    {
        return is_numeric(str_replace([',', ' '], '', $value));
    }

    /**
     * Get suggested field keys for a detected field type.
     *
     * Queries actual custom fields configured for the entity.
     *
     * @return array<string>
     */
    private function getSuggestedFieldsForType(string $fieldTypeKey): array
    {
        if ($this->entityName === null || $this->teamId === null) {
            return [];
        }

        // Query custom fields of this type for the entity
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('entity_type', $this->entityName)
            ->where('tenant_id', $this->teamId)
            ->where('type', $fieldTypeKey)
            ->active()
            ->pluck('code')
            ->map(fn (string $code): string => "custom_fields_{$code}")
            ->all();
    }
}
