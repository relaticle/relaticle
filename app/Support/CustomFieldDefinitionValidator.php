<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\CustomFields\CreateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/**
 * The single rule set for custom-field definitions written outside a Filament form.
 *
 * The management form enforces per-tenant, per-entity uniqueness on a field's name
 * and code (see the package's FieldForm); nothing enforced it on the chat/action
 * path, so an assistant could create a second "Age" on People that the UI forbids.
 * Both the proposal tools (pre-flight, so a doomed proposal is never shown) and the
 * actions (authoritative, so a proposal approved after the name was taken still
 * fails) validate through here, which keeps the two from drifting.
 *
 * Uniqueness runs on the query builder rather than Eloquent so deactivated fields
 * count as taken — the activable global scope would otherwise hide them and let a
 * duplicate through.
 */
final readonly class CustomFieldDefinitionValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forCreate(User $user, array $data): array
    {
        $entityType = is_string($data['entity_type'] ?? null) ? $data['entity_type'] : '';
        $type = is_string($data['type'] ?? null) ? $data['type'] : '';
        $tenantId = $user->currentTeam->getKey();
        $maxOptions = self::maxOptions();

        return Validator::make(self::normalize($data), [
            'entity_type' => ['required', Rule::in(CreateCustomField::VALID_ENTITY_TYPES), self::withinFieldCap($tenantId, $entityType)],
            'type' => ['required', Rule::in(CreateCustomField::ALLOWED_TYPES)],
            'name' => ['required', 'string', 'max:50', self::uniqueDefinition('name', $tenantId, $entityType)],
            'code' => ['nullable', 'string', 'max:50', 'alpha_dash', self::uniqueDefinition('code', $tenantId, $entityType)],
            'options' => ['nullable', self::expectsOptions($type) ? 'required' : 'prohibited', 'array', "max:{$maxOptions}"],
            'options.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
        ], [
            'entity_type.in' => 'Invalid entity type ":input". Must be one of: '.implode(', ', CreateCustomField::VALID_ENTITY_TYPES).'.',
            'type.in' => 'Field type ":input" is not supported via chat. Allowed types: '.implode(', ', CreateCustomField::ALLOWED_TYPES).'.',
            'name.required' => 'A field name is required.',
            'name.max' => 'Field names must be 50 characters or fewer.',
            'name.unique' => "A field named \":input\" already exists on {$entityType}. Field names must be unique per entity — pick a different name, or update the existing field instead.",
            'code.max' => 'Field codes must be 50 characters or fewer.',
            'code.alpha_dash' => 'Field codes may only contain letters, numbers, dashes, and underscores.',
            'code.unique' => "A field with code \":input\" already exists on {$entityType}. Omit the code to auto-generate a unique one, or pick a different code.",
            'options.required' => "Field type \"{$type}\" requires at least one option.",
            'options.prohibited' => "Field type \"{$type}\" does not support options.",
            'options.max' => "Too many options — at most {$maxOptions} per field.",
        ] + self::optionNameMessages())->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forRename(User $user, CustomField $field, array $data): array
    {
        $entityType = (string) $field->entity_type;

        return Validator::make(self::normalize($data), [
            'name' => [
                'required_without:active', 'string', 'max:50',
                self::uniqueDefinition('name', $user->currentTeam->getKey(), $entityType)->ignore($field->getKey()),
            ],
            // Only `name` carries required_without: with the rule on both, an empty
            // payload failed twice and the assistant was handed the same sentence twice.
            'active' => ['nullable', 'boolean'],
        ], [
            'name.required_without' => 'Provide at least one of: name, active.',
            'name.max' => 'Field names must be 50 characters or fewer.',
            'name.unique' => "A field named \":input\" already exists on {$entityType}. Field names must be unique per entity — pick a different name.",
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forNewOptions(User $user, CustomField $field, array $data): array
    {
        $maxOptions = self::maxOptions();
        $existing = DB::table(self::optionsTable())
            ->where('custom_field_id', $field->getKey())
            ->count();
        $remaining = max(0, $maxOptions - $existing);

        return Validator::make(self::normalize($data), [
            'options' => ['nullable', 'required', 'array', "max:{$remaining}"],
            'options.*.name' => [
                'required', 'string', 'max:255', 'distinct:ignore_case',
                Rule::unique(self::optionsTable(), 'name')->where(
                    fn (Builder $query): Builder => $query
                        ->where('custom_field_id', $field->getKey())
                        ->where(self::tenantKey(), $user->currentTeam->getKey()),
                ),
            ],
        ], [
            'options.required' => 'At least one option must be provided.',
            'options.max' => "Adding these options would exceed the {$maxOptions} options limit for this field (currently has {$existing}).",
        ] + self::optionNameMessages())->validate();
    }

    /**
     * @return array<string, string>
     */
    private static function optionNameMessages(): array
    {
        return [
            'options.*.name.required' => 'Option names cannot be empty.',
            'options.*.name.distinct' => 'Duplicate option names are not allowed.',
            'options.*.name.unique' => 'Option ":input" already exists on this field.',
            'options.*.name.max' => 'Option names must be 255 characters or fewer.',
        ];
    }

    /**
     * Uniqueness is scoped the same way the management form scopes it: per tenant,
     * per entity type.
     */
    private static function uniqueDefinition(string $column, int|string $tenantId, string $entityType): Unique
    {
        return Rule::unique(self::definitionsTable(), $column)->where(
            fn (Builder $query): Builder => $query
                ->where(self::tenantKey(), $tenantId)
                ->where('entity_type', $entityType),
        );
    }

    private static function withinFieldCap(int|string $tenantId, string $entityType): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($tenantId, $entityType): void {
            $max = (int) config('chat.max_custom_fields_per_entity', 50);

            $existing = DB::table(self::definitionsTable())
                ->where(self::tenantKey(), $tenantId)
                ->where('entity_type', $entityType)
                ->count();

            if ($existing >= $max) {
                $fail("Cannot create more than {$max} custom fields for entity type \"{$entityType}\".");
            }
        };
    }

    /**
     * Trims the free-text attributes and reshapes options to a uniform
     * `[{name: string}]` so the `options.*.name` rules apply whether the caller
     * sent objects or bare strings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        foreach (['name', 'code'] as $attribute) {
            if (is_string($data[$attribute] ?? null)) {
                $data[$attribute] = trim($data[$attribute]);
            }
        }

        if (is_array($data['options'] ?? null)) {
            $data['options'] = array_values(array_map(
                static fn (mixed $option): array => [
                    'name' => trim(is_array($option) ? (string) ($option['name'] ?? '') : (string) $option),
                ],
                $data['options'],
            ));
        }

        return $data;
    }

    private static function expectsOptions(string $type): bool
    {
        return in_array($type, CreateCustomField::CHOICE_TYPES, true);
    }

    private static function maxOptions(): int
    {
        return (int) config('chat.max_field_options', 50);
    }

    private static function definitionsTable(): string
    {
        return (string) config('custom-fields.database.table_names.custom_fields');
    }

    private static function optionsTable(): string
    {
        return (string) config('custom-fields.database.table_names.custom_field_options');
    }

    private static function tenantKey(): string
    {
        return (string) config('custom-fields.database.column_names.tenant_foreign_key');
    }
}
