<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Facades\CustomFieldsType;

final readonly class CustomFieldOptionMap
{
    /**
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array{ids: array<string, list<string>>, labels: list<string>}>
     */
    public function fromFields(Collection $fields): array
    {
        $map = [];

        foreach ($fields as $field) {
            $ids = [];
            $labels = [];

            foreach ($field->options as $option) {
                $label = (string) $option->name;
                $ids[mb_strtolower(trim($label))][] = (string) $option->getKey();
                $labels[] = $label;
            }

            $map[(string) $field->code] = ['ids' => $ids, 'labels' => $labels];
        }

        return $map;
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    public function idFor(array $entry, string $value): ?string
    {
        if (in_array($value, Arr::flatten($entry['ids']), true)) {
            return $value;
        }

        $matches = $entry['ids'][mb_strtolower(trim($value))] ?? [];

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  array{ids: array<string, list<string>>, labels: list<string>}  $entry
     */
    public function isAmbiguous(array $entry, string $label): bool
    {
        return count($entry['ids'][mb_strtolower(trim($label))] ?? []) > 1;
    }

    public function translates(CustomField $field): bool
    {
        $type = CustomFieldType::tryFrom($field->type);

        return $type !== null && self::picksFromOptions($type) && ! $field->relationshipDefinition() instanceof CustomFieldRelationship;
    }

    public static function choiceRule(): string
    {
        $types = array_filter(CustomFieldType::cases(), self::picksFromOptions(...));

        return ucfirst(Arr::join(array_map(static fn (CustomFieldType $type): string => $type->value, $types), ', ', ' and ')).' values take an option label or ID.';
    }

    private static function picksFromOptions(CustomFieldType $type): bool
    {
        return $type->isChoice() && CustomFieldsType::getFieldType($type->value)?->acceptsArbitraryValues === false;
    }
}
