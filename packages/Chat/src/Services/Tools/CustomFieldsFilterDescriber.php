<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;

/**
 * The read-path twin of {@see CustomFieldsSchemaDescriber}.
 *
 * Most of what a CRM user filters on — stage, status, due date, priority, amount —
 * lives in custom fields, so a list tool without them can only ever answer "all of
 * them". This inlines the tenant's filterable codes, their operators and their
 * option labels into the tool's `custom_fields` slot, so the assistant can build a
 * correct filter without a discovery round-trip.
 *
 * Filterability and operators come from {@see CustomFieldFilterSchema} — the same
 * source the MCP server uses — so the two surfaces cannot drift apart.
 */
final readonly class CustomFieldsFilterDescriber
{
    public function __construct(
        private CustomFieldFilterSchema $filterSchema,
    ) {}

    public function describe(User $user, string $entityType): string
    {
        $schema = $this->filterSchema->build($user, $entityType);

        if ($schema === []) {
            return 'No filterable custom fields are defined for this entity type.';
        }

        $optionLabels = $this->optionLabels($user->currentTeam, $entityType, array_keys($schema));

        $lines = [
            'Filter by custom field values. Keys MUST be one of the codes below; each value is an object of operator => operand.',
            'For choice fields pass the option LABEL exactly as listed, not an ID.',
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
        $lines[] = 'Example: {"'.array_key_first($schema).'": {"eq": "..."}}';

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
     * Narrowed to the codes actually being described, so tenants whose filterable
     * fields are all non-choice skip the options join entirely.
     *
     * @param  list<string>  $codes
     * @return array<string, list<string>>
     */
    private function optionLabels(Team $team, string $entityType, array $codes): array
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('entity_type', $entityType)
            ->whereIn('code', $codes)
            ->active()
            ->with(['options:id,custom_field_id,name'])
            ->select('id', 'code')
            ->get()
            ->mapWithKeys(fn (CustomField $field): array => [
                (string) $field->code => array_values(array_map(strval(...), $field->options->pluck('name')->all())),
            ])
            ->all();
    }
}
