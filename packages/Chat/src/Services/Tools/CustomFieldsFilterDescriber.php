<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\FilterVocabulary;

/**
 * The read-path twin of {@see CustomFieldsSchemaDescriber}.
 *
 * Most of what a CRM user filters on (stage, status, due date, priority, amount)
 * lives in custom fields, so a list tool without them can only ever answer "all of
 * them". This inlines the whole {@see FilterVocabulary} into the tool's `filter`
 * slot, so the assistant can build a correct filter without a discovery round-trip.
 *
 * The vocabulary is the source MCP publishes too, so the two surfaces cannot drift apart.
 */
final readonly class CustomFieldsFilterDescriber
{
    public function __construct(
        private FilterVocabulary $vocabulary,
        private CustomFieldFilterSchema $filterSchema,
    ) {}

    public function describe(User $user, string $entityType): string
    {
        $entity = CrmEntity::from($entityType);
        $vocabulary = $this->vocabulary->for($user, $entity);
        $customFields = $vocabulary['custom_fields'];
        $types = $vocabulary['types'];
        unset($vocabulary['custom_fields'], $vocabulary['types']);

        $lines = ['Names for this entity type:'];

        foreach ($vocabulary as $name => $entry) {
            $line = "- {$name} ({$entry['type']}".(isset($entry['entity']) ? " to {$entry['entity']}" : '').'; operators: '.implode(', ', $entry['operators']);
            $line .= isset($entry['values']) ? '; one of: '.implode(', ', $entry['values']) : '';
            $line .= isset($entry['operand']) ? "; takes {$entry['operand']}" : '';
            $lines[] = $line.'; example: '.CustomFieldFilterSchema::json($entry['example']).')';
        }

        $lines[] = '';
        $lines[] = 'Example: '.CustomFieldFilterSchema::json(EntityFilters::example($entity));

        if ($customFields === []) {
            $lines[] = '';
            $lines[] = 'No filterable custom fields are defined for this entity type.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = 'Custom field conditions go under custom_fields. Their keys MUST be one of the codes below; each value is an object of operator => operand, as its type allows.';
        $lines[] = 'For choice fields pass the option label as listed; an option ID also works.';
        $lines[] = '';
        $lines[] = 'Field types:';

        foreach ($types as $type => $entry) {
            $line = "- {$type}: operators ".implode(', ', $entry['operators']);
            $line .= isset($entry['sub_fields']) ? '; sub-field domain takes '.implode(', ', $entry['sub_fields']['domain']['operators'])." and matches {$entry['sub_fields']['domain']['matches']}, example ".CustomFieldFilterSchema::json($entry['sub_fields']['domain']['example']) : '';
            $line .= isset($entry['matching']) ? "; values match {$entry['matching']}" : '';
            $lines[] = $line.'; example '.CustomFieldFilterSchema::json($entry['example']);
        }

        $lines[] = '';
        $lines[] = 'Fields:';

        foreach ($customFields as $code => $entry) {
            $lines[] = "- {$code} ({$entry['name']}, {$entry['type']}".(isset($entry['options']) ? '; one of: "'.implode('", "', $entry['options']).'"' : '').')';
        }

        $firstCode = array_key_first($customFields);
        $lines[] = '';
        $lines[] = 'Custom field example: '.CustomFieldFilterSchema::json(['custom_fields' => [$firstCode => $types[$customFields[$firstCode]['type']]['example']]]);

        return implode("\n", $lines);
    }

    /**
     * The codes accepted by the `sort` slot, alongside the native columns.
     *
     * @return list<string>
     */
    public function sortableCodes(User $user, string $entityType): array
    {
        return array_keys($this->filterSchema->build($user, $entityType));
    }
}
