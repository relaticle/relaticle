<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\WorkspaceCustomFields;

/**
 * The read-path twin of {@see CustomFieldsSchemaDescriber}.
 *
 * Most of what a CRM user filters on (stage, status, due date, priority, amount)
 * lives in custom fields, so a list tool without them can only ever answer "all of
 * them". This inlines the tenant's filterable codes, their operators and their
 * option labels into the tool's `custom_fields` slot, so the assistant can build a
 * correct filter without a discovery round-trip.
 *
 * Filterability and operators come from {@see CustomFieldFilterSchema}, the same
 * source the MCP server uses, so the two surfaces cannot drift apart.
 */
final readonly class CustomFieldsFilterDescriber
{
    public function __construct(
        private CustomFieldFilterSchema $filterSchema,
        private WorkspaceCustomFields $customFields,
        private CustomFieldOptionMap $optionMap,
    ) {}

    public function describe(User $user, string $entityType): string
    {
        $schema = $this->filterSchema->build($user, $entityType);

        if ($schema === []) {
            return 'No filterable custom fields are defined for this entity type.';
        }

        $optionLabels = $this->optionLabels($user->currentWorkspace, $entityType, array_keys($schema));

        $lines = [
            'Filter by custom field values. Keys MUST be one of the codes below; each value is an object of operator => operand.',
            'For choice fields pass the option label as listed; an option ID also works. $not_in and $has_none also match records where the field is empty. $is_empty takes true or false.',
            '',
        ];

        foreach ($schema as $code => $definition) {
            $operators = implode(', ', array_keys(is_array($definition['properties'] ?? null) ? $definition['properties'] : []));
            $line = "- {$code} (".($definition['description'] ?? $code)."; operators: {$operators}";

            if (($optionLabels[$code] ?? []) !== []) {
                $line .= '; one of: "'.implode('", "', $optionLabels[$code]).'"';
            }

            $lines[] = $line.')';
        }

        $lines[] = '';
        $lines[] = 'Example: {"'.array_key_first($schema).'": {"$eq": "..."}}';

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

    /**
     * @param  list<string>  $codes
     * @return array<string, list<string>>
     */
    private function optionLabels(Workspace $workspace, string $entityType, array $codes): array
    {
        return $this->customFields->forEntity($workspace, $entityType)
            ->where('active', true)
            ->whereIn('code', $codes)
            ->filter($this->optionMap->translates(...))
            ->mapWithKeys(fn (CustomField $field): array => [
                (string) $field->code => array_values(array_map(strval(...), $field->options->pluck('name')->all())),
            ])
            ->all();
    }
}
