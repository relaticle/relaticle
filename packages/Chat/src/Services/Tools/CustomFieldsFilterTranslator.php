<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\User;
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
        private CustomFieldOptionMap $optionMap,
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

                $translated[$code][$operator] = $this->translateOperand($code, $options[$code] ?? ['ids' => [], 'labels' => []], $operand);
            }
        }

        return $translated;
    }

    /**
     * Choice fields match on option IDs; a field with no options passes through as sent.
     *
     * @param  array{ids: array<string, string>, labels: list<string>}  $entry
     */
    private function translateOperand(string $code, array $entry, mixed $operand): mixed
    {
        if ($entry['ids'] === []) {
            return $operand;
        }

        if (is_array($operand)) {
            return array_map(fn (mixed $label): string => $this->optionId($code, $entry, $label), $operand);
        }

        return $this->optionId($code, $entry, $operand);
    }

    /**
     * @param  array{ids: array<string, string>, labels: list<string>}  $entry
     */
    private function optionId(string $code, array $entry, mixed $label): string
    {
        $id = $this->optionMap->idFor($entry, (string) $label);

        if ($id === null) {
            // The stored casing, not the lowercased match keys — this string is read
            // by the assistant and echoed to the user.
            throw ValidationException::withMessages([
                'custom_fields' => "\"{$label}\" is not one of the options for \"{$code}\". Available: ".
                    implode(', ', $entry['labels'] === [] ? ['none'] : $entry['labels']).'.',
            ]);
        }

        return $id;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, array{ids: array<string, string>, labels: list<string>}>
     */
    private function optionsByCode(User $user, string $entityType, array $codes): array
    {
        return $this->optionMap->fromFields(
            CustomField::query()
                ->where('tenant_id', $user->currentTeam->getKey())
                ->where('entity_type', $entityType)
                ->whereIn('code', $codes)
                ->active()
                ->with('options')
                ->get(),
        );
    }
}
