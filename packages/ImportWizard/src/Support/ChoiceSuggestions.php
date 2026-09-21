<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Support\CustomFields\OptionMatch;
use App\Support\CustomFields\OptionMatcher;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Support\Validation\ValidationError;

final readonly class ChoiceSuggestions
{
    private const int TIMEOUT_SECONDS = 20;

    private const int MAX_ITEMS = 100;

    public function __construct(private OptionMatcher $optionMatcher) {}

    /**
     * @param  array<int, array{raw_value: string, validation_error: string|null}>  $results
     * @return array<array-key, string>
     */
    public function forColumn(ColumnData $column, array $results): array
    {
        if (! $column->isSingleChoicePredefined() && ! $column->isMultiChoicePredefined()) {
            return [];
        }

        $invalidItemsByValue = [];

        foreach ($results as $result) {
            $error = ValidationError::fromStorageFormat($result['validation_error']);

            if (! $error instanceof ValidationError) {
                continue;
            }

            $invalidItemsByValue[$result['raw_value']] = $error->hasItemErrors()
                ? array_map(strval(...), array_keys($error->getItemErrors()))
                : [$result['raw_value']];
        }

        if ($invalidItemsByValue === []) {
            return [];
        }

        $items = array_slice(array_values(array_unique(array_merge(...array_values($invalidItemsByValue)))), 0, self::MAX_ITEMS);

        $options = collect($column->importField->options ?? [])
            ->mapWithKeys(fn (array $option): array => [(string) $option['value'] => (string) $option['label']])
            ->all();

        $matches = $this->optionMatcher->match($column->getLabel(), $options, $items, self::TIMEOUT_SECONDS);

        $suggestions = [];

        foreach ($invalidItemsByValue as $rawValue => $invalidItems) {
            if (array_any($invalidItems, fn (string $item): bool => ! isset($matches[$item]))) {
                continue;
            }

            $suggestions[(string) $rawValue] = $column->isMultiChoicePredefined()
                ? $this->replaceItems((string) $rawValue, $matches)
                : $matches[$rawValue]->key;
        }

        return $suggestions;
    }

    /**
     * @param  array<array-key, OptionMatch>  $matches
     */
    private function replaceItems(string $rawValue, array $matches): string
    {
        return str($rawValue)
            ->explode(',')
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->map(fn (string $item): string => isset($matches[$item]) ? $matches[$item]->key : $item)
            ->unique()
            ->implode(', ');
    }
}
