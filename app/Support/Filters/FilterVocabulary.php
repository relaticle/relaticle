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

        ['types' => $vocabulary['types'], 'fields' => $vocabulary['custom_fields']] = $this->customFieldEntries($user, $entity);

        return $vocabulary;
    }

    /**
     * @return array{types: array<string, array<string, mixed>>, fields: array<string, array<string, mixed>>}
     */
    private function customFieldEntries(User $user, CrmEntity $entity): array
    {
        $schema = $this->filterSchema->build($user, $entity->value);
        $fields = $this->customFields->forEntity($user->currentWorkspace, $entity->value)->keyBy('code');
        $types = [];
        $entries = [];
        $fieldBound = [];

        foreach ($schema as $code => $definition) {
            $field = $fields->get($code);

            if (! $field instanceof CustomField) {
                continue;
            }

            $options = $this->optionMap->translates($field)
                ? array_values(array_map(strval(...), $field->options->pluck('name')->all()))
                : [];
            $properties = is_array($definition['properties'] ?? null) ? $definition['properties'] : [];
            $entry = ['name' => $definition['description'] ?? $code, 'type' => $field->type];

            if ($options !== []) {
                $entry['options'] = $options;
            }

            if ($this->optionMap->translates($field) || $field->lookup_type !== null) {
                $fieldBound[$field->type] = true;
            }

            $entries[$code] = ['entry' => $entry, 'field' => $field, 'options' => $options];
            $types[$field->type] ??= $this->typeEntry($field, $properties);
        }

        foreach ($entries as $code => ['entry' => $entry, 'field' => $field, 'options' => $options]) {
            if (isset($fieldBound[$field->type])) {
                $entry['example'] = $this->example($field, $options);
            } else {
                $types[$field->type]['example'] ??= $this->example($field, []);
            }

            $entries[$code] = $entry;
        }

        return ['types' => $types, 'fields' => $entries];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function typeEntry(CustomField $field, array $properties): array
    {
        $entry = ['operators' => array_values(array_filter(array_keys($properties), static fn (string $key): bool => str_starts_with($key, '$')))];
        $matching = CustomFieldType::tryFrom($field->type)?->filterMatching();

        if ($matching !== null) {
            $entry['matching'] = $matching;
        }

        if (isset($properties['domain'])) {
            $entry['sub_fields'] = ['domain' => [
                'operators' => CustomFieldFilterSchema::DOMAIN_OPERATORS,
                'matches' => CustomFieldFilterSchema::DOMAIN_MEANING,
                'example' => CustomFieldFilterSchema::DOMAIN_EXAMPLE,
            ]];
        }

        return $entry;
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function example(CustomField $field, array $options): array
    {
        $example = CustomFieldType::tryFrom($field->type)?->filterExample() ?? [];

        if ($example !== [] && $this->optionMap->translates($field)) {
            $example = $options === [] ? [] : [array_key_first($example) => [$options[0]]];
        }

        return $example === [] ? ['$is_empty' => false] : $example;
    }
}
