<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\WorkspaceCustomFields;
use BackedEnum;

final readonly class FilterVocabulary
{
    public function __construct(
        private CustomFieldFilterSchema $filterSchema,
        private WorkspaceCustomFields $customFields,
        private CustomFieldOptionMap $optionMap,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user, CrmEntity $entity): array
    {
        $vocabulary = [];

        foreach (EntityFilters::definitions($entity) as $name => $definition) {
            $entry = ['type' => $definition->kind->value, 'operators' => $definition->operators()];

            if ($definition->related instanceof CrmEntity) {
                $entry['entity'] = $definition->related->value;
            }

            if ($definition->enumClass !== null) {
                $entry['values'] = array_map(static fn (BackedEnum $case): string => (string) $case->value, $definition->enumClass::cases());
            }

            if ($definition->operand() !== null) {
                $entry['operand'] = $definition->operand();
            }

            $entry['example'] = $definition->example();
            $vocabulary[$name] = $entry;
        }

        $vocabulary['custom_fields'] = $this->customFieldEntries($user, $entity);

        return $vocabulary;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function customFieldEntries(User $user, CrmEntity $entity): array
    {
        $schema = $this->filterSchema->build($user, $entity->value);
        $fields = $this->customFields->forEntity($user->currentWorkspace, $entity->value)->keyBy('code');
        $entries = [];

        foreach ($schema as $code => $definition) {
            $properties = is_array($definition['properties'] ?? null) ? $definition['properties'] : [];
            $field = $fields->get($code);
            $operators = array_values(array_filter(array_keys($properties), static fn (string $key): bool => str_starts_with($key, '$')));
            $options = $field instanceof CustomField && $this->optionMap->translates($field)
                ? array_values(array_map(strval(...), $field->options->pluck('name')->all()))
                : [];

            $entry = [
                'name' => $definition['description'] ?? $code,
                'type' => $field instanceof CustomField ? $field->type : null,
                'operators' => $operators,
            ];

            $matching = $field instanceof CustomField ? CustomFieldType::tryFrom($field->type)?->filterMatching() : null;

            if ($matching !== null) {
                $entry['matching'] = $matching;
            }

            if (isset($properties['domain'])) {
                $entry['sub_fields'] = ['domain' => CustomFieldFilterSchema::DOMAIN_OPERATORS];
            }

            if ($options !== []) {
                $entry['options'] = $options;
            }

            $entry['example'] = $this->example($field, $options);
            $entries[$code] = $entry;
        }

        return $entries;
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function example(?CustomField $field, array $options): array
    {
        if (! $field instanceof CustomField) {
            return ['$is_empty' => false];
        }

        $example = CustomFieldType::tryFrom($field->type)?->filterExample() ?? [];

        if ($example !== [] && $this->optionMap->translates($field)) {
            $example = $options === [] ? [] : [array_key_first($example) => [$options[0]]];
        }

        return $example === [] ? ['$is_empty' => false] : $example;
    }
}
