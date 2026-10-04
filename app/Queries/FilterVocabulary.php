<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use App\Models\User;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\WorkspaceCustomFields;

final readonly class FilterVocabulary
{
    public function __construct(
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
                $title = $definition->related->titleColumn();
                $entry['nested_example'] = [$title => EntityFilters::definitions($definition->related)[$title]->example()];
                $customFieldExample = $this->firstCustomFieldExample($user, $definition->related);

                if ($customFieldExample !== null) {
                    $entry['nested_custom_field_example'] = $customFieldExample;
                }
            }

            if ($definition->enumClass !== null) {
                $entry['values'] = $definition->enumValues();
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
     * @return array<string, array<string, mixed>>|null
     */
    public function firstCustomFieldExample(User $user, CrmEntity $entity): ?array
    {
        ['types' => $types, 'fields' => $fields] = $this->customFieldEntries($user, $entity);
        $code = array_key_first($fields);

        return $code === null
            ? null
            : ['custom_fields' => [$code => $fields[$code]['example'] ?? $types[$fields[$code]['type']]['example']]];
    }

    /**
     * @return array{types: array<string, array<string, mixed>>, fields: array<string, array<string, mixed>>}
     */
    private function customFieldEntries(User $user, CrmEntity $entity): array
    {
        /** @var array{types: array<string, array<string, mixed>>, fields: array<string, array<string, mixed>>} */
        return $this->customFields->remember(
            $user->currentWorkspace,
            "filter_vocabulary:{$entity->value}",
            fn (): array => $this->buildCustomFieldEntries($user, $entity),
        );
    }

    /**
     * @return array{types: array<string, array<string, mixed>>, fields: array<string, array<string, mixed>>}
     */
    private function buildCustomFieldEntries(User $user, CrmEntity $entity): array
    {
        $fields = $this->customFields->forEntity($user->currentWorkspace, $entity->value)->filter(CustomFieldFilterSchema::isFilterable(...));
        $types = [];
        $entries = [];
        $fieldBound = [];

        foreach ($fields as $field) {
            $code = $field->code;
            $options = $this->optionMap->translates($field)
                ? array_values(array_map(strval(...), $field->options->pluck('name')->all()))
                : [];
            $entry = ['name' => $field->name, 'type' => $field->type];

            if ($options !== []) {
                $entry['options'] = $options;
            }

            if ($this->optionMap->translates($field) || $field->relationshipDefinition() instanceof CustomFieldRelationship) {
                $fieldBound[$field->type] = true;
            }

            $entries[$code] = ['entry' => $entry, 'field' => $field, 'options' => $options];
            $types[$field->type] ??= $this->typeEntry($field);
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
     * @return array<string, mixed>
     */
    private function typeEntry(CustomField $field): array
    {
        $entry = ['operators' => CustomFieldFilterSchema::operatorKeys($field->type)];
        $matching = CustomFieldType::tryFrom($field->type)?->filterMatching();

        if ($matching !== null) {
            $entry['matching'] = $matching;
        }

        if (isset(CustomFieldFilterSchema::operatorsForType($field->type)['domain'])) {
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
