<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns the assistant's `custom_fields` filter into the shape CustomFieldFilter
 * matches on.
 *
 * Choice values are stored as option IDs, but the assistant only ever sees labels
 * (the convention every chat tool follows — see CustomFieldsRequestValidator on the
 * write path), so labels are translated here. An unknown code, operator or label is
 * rejected rather than silently dropped: a filter that quietly does nothing returns
 * the whole table, which reads as a confident, wrong answer.
 */
final readonly class CustomFieldsFilterTranslator
{
    public function __construct(
        private CustomFieldFilterSchema $filterSchema,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function translate(User $user, string $entityType, mixed $rawFilters): array
    {
        if (! is_array($rawFilters) || $rawFilters === []) {
            return [];
        }

        $filterable = $this->filterSchema->build($user, $entityType);
        $codes = array_map(strval(...), array_keys($rawFilters));
        $options = $this->optionsByCode($user, $entityType, $codes);

        $translated = [];

        foreach ($rawFilters as $rawCode => $operators) {
            $code = (string) $rawCode;

            if (! isset($filterable[$code])) {
                throw ValidationException::withMessages([
                    'custom_fields' => "\"{$code}\" is not a filterable custom field on {$entityType}. Available: ".
                        ($filterable === [] ? 'none' : implode(', ', array_keys($filterable))).'.',
                ]);
            }

            if (! is_array($operators)) {
                throw ValidationException::withMessages([
                    'custom_fields' => "custom_fields.{$code} must be an object of operator => value, e.g. {\"eq\": \"...\"}.",
                ]);
            }

            $properties = $filterable[$code]['properties'] ?? null;
            $allowed = is_array($properties) ? array_map(strval(...), array_keys($properties)) : [];

            foreach ($operators as $rawOperator => $operand) {
                $operator = (string) $rawOperator;

                if (! in_array($operator, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'custom_fields' => "Operator \"{$operator}\" is not supported for \"{$code}\". Supported: ".implode(', ', $allowed).'.',
                    ]);
                }

                $translated[$code][$operator] = $this->translateOperand($code, $options[$code] ?? [], $operand);
            }
        }

        return $translated;
    }

    /**
     * Choice fields match on option IDs; a field with no options passes through as sent.
     *
     * @param  array<string, string>  $options  lowercased label => option id
     */
    private function translateOperand(string $code, array $options, mixed $operand): mixed
    {
        if ($options === []) {
            return $operand;
        }

        if (is_array($operand)) {
            return array_map(fn (mixed $label): string => $this->optionId($code, $options, $label), $operand);
        }

        return $this->optionId($code, $options, $operand);
    }

    /**
     * @param  array<string, string>  $options  lowercased label => option id
     */
    private function optionId(string $code, array $options, mixed $label): string
    {
        $id = $options[mb_strtolower((string) $label)] ?? null;

        if ($id === null) {
            throw ValidationException::withMessages([
                'custom_fields' => "\"{$label}\" is not one of the options for \"{$code}\". Available: ".implode(', ', $options === [] ? ['none'] : array_keys($options)).'.',
            ]);
        }

        return $id;
    }

    /**
     * Lowercased option label => option id, per field code.
     *
     * @param  list<string>  $codes
     * @return array<string, array<string, string>>
     */
    private function optionsByCode(User $user, string $entityType, array $codes): array
    {
        $fieldsTable = (string) config('custom-fields.database.table_names.custom_fields');
        $optionsTable = (string) config('custom-fields.database.table_names.custom_field_options');
        $tenantKey = (string) config('custom-fields.database.column_names.tenant_foreign_key');

        $rows = DB::table("{$fieldsTable} as f")
            ->join("{$optionsTable} as o", 'o.custom_field_id', '=', 'f.id')
            ->where("f.{$tenantKey}", $user->currentTeam->getKey())
            ->where('f.entity_type', $entityType)
            ->whereIn('f.code', $codes)
            ->get(['f.code as field_code', 'o.id as option_id', 'o.name as option_name']);

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row->field_code][mb_strtolower((string) $row->option_name)] = (string) $row->option_id;
        }

        return $options;
    }
}
