<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\CustomField;
use App\Models\Workspace;
use Relaticle\CustomFields\QueryBuilders\CustomFieldQueryBuilder;

/**
 * Which custom fields represent an entity in a chat display block.
 *
 * Nothing chat-specific is configured: the workspace already told us, on every
 * custom field, what its Filament table and view page show. `listFields()`
 * mirrors the table, `cardFields()` mirrors the view page, both in the workspace's
 * own `sort_order`. A chat-only template would be a second source of truth for
 * a fact these settings already own.
 *
 * The workspace is always passed explicitly. These run inside the queued chat job,
 * where no Filament tenant is bound and the package's TenantScope contributes
 * nothing.
 */
final readonly class DisplayFieldSelector
{
    /**
     * Fields the Filament TABLE shows by default: visible in list, and not
     * hidden behind the column toggle. The toggle flag is read off the cast
     * settings rather than the JSON column because it resolves through a
     * package feature flag when unset, exactly as FieldColumnFactory reads it.
     *
     * @return list<CustomField>
     */
    public function listFields(Workspace $workspace, string $entityType): array
    {
        /** @var list<CustomField> $fields */
        $fields = $this->baseQuery($workspace, $entityType)->visibleInList()->get()->all();

        return array_values(array_filter(
            $fields,
            static fn (CustomField $field): bool => $field->settings->list_toggleable_hidden !== true,
        ));
    }

    /**
     * Fields the Filament VIEW page shows. No toggle filter: the toggle is a
     * table affordance and does not exist on the view page.
     *
     * @return list<CustomField>
     */
    public function cardFields(Workspace $workspace, string $entityType): array
    {
        /** @var list<CustomField> $fields */
        $fields = $this->baseQuery($workspace, $entityType)->visibleInView()->get()->all();

        return $fields;
    }

    /**
     * The workspace's active fields for this entity, in its own display order,
     * before either surface's visibility scope is applied.
     *
     * The tenant is always an explicit predicate: this runs inside the queued
     * chat job, where the package's TenantScope resolves to nothing.
     *
     * @return CustomFieldQueryBuilder<CustomField>
     */
    private function baseQuery(Workspace $workspace, string $entityType): CustomFieldQueryBuilder
    {
        return CustomField::query()
            ->where('tenant_id', $workspace->getKey())
            ->where('entity_type', $entityType)
            ->active()
            // Choice values render through the option list, and this hydrates
            // many rows at once: lazy loading here trips strict mode.
            ->with('options')
            ->orderBy('sort_order');
    }
}
