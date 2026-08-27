<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use Illuminate\Support\Collection;

/**
 * The one place that answers "which option id does this label mean?".
 *
 * The read path (filtering) and the write path (setting a value) both take option
 * LABELS from the assistant and both have to reach an option id, and they used to
 * do it with two separate lookups that had already drifted: one matched labels
 * case-sensitively, the other did not, so the same string could be accepted when
 * filtering and rejected when saving. Policy still belongs to each caller — the
 * write path alone cares about `acceptsArbitraryValues` and lookup fields — but
 * the lookup itself lives here.
 *
 * Matching ignores case, because the assistant echoes labels back in whatever
 * casing the sentence used and an option list is not a set of identifiers.
 */
final readonly class CustomFieldOptionMap
{
    /**
     * Labels are kept in their stored casing for display, alongside a lowercased
     * index for matching.
     *
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, array{ids: array<string, string>, labels: list<string>}>
     */
    public function fromFields(Collection $fields): array
    {
        $map = [];

        foreach ($fields as $field) {
            $ids = [];
            $labels = [];

            foreach ($field->options as $option) {
                $label = (string) $option->name;
                $ids[mb_strtolower($label)] = (string) $option->getKey();
                $labels[] = $label;
            }

            $map[(string) $field->code] = ['ids' => $ids, 'labels' => $labels];
        }

        return $map;
    }

    /**
     * @param  array{ids: array<string, string>, labels: list<string>}  $entry
     */
    public function idFor(array $entry, string $label): ?string
    {
        return $entry['ids'][mb_strtolower($label)] ?? null;
    }
}
