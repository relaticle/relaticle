<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Enums\FieldCapability;
use Relaticle\CustomFields\Facades\CustomFieldsType;

final class WorkspaceCustomFields
{
    /** @var array<string, Collection<int, CustomField>> */
    private array $byTenant = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $derived = [];

    /**
     * @return Collection<int, CustomField>
     */
    public function forEntity(Workspace|string $workspace, string $entityType): Collection
    {
        return $this->all($workspace)
            ->where('entity_type', $entityType)
            ->values();
    }

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public function remember(Workspace $workspace, string $key, Closure $build): array
    {
        return $this->derived[(string) $workspace->getKey()][$key] ??= $build();
    }

    /**
     * @return Collection<int, CustomField>
     */
    public function linkFields(Workspace|string $workspace, string $entityType): Collection
    {
        return $this->all($workspace)
            ->where('entity_type', $entityType)
            ->filter(static fn (CustomField $field): bool => $field->active && $field->relationshipDefinition() instanceof CustomFieldRelationship)
            ->values();
    }

    public function forget(int|string $tenantId): void
    {
        unset($this->byTenant[(string) $tenantId], $this->derived[(string) $tenantId]);
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function all(Workspace|string $workspace): Collection
    {
        $tenantId = $workspace instanceof Workspace ? (string) $workspace->getKey() : $workspace;

        if (isset($this->byTenant[$tenantId])) {
            return $this->byTenant[$tenantId];
        }

        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['options' => function (HasMany $query): void {
                $query->withoutGlobalScopes();
            }])
            ->get();

        $fields
            ->filter(static fn (CustomField $field): bool => CustomFieldsType::getFieldType($field->type)?->supports(FieldCapability::RequiresRelationship) === true)
            ->load(['fromEndDefinition', 'toEndDefinition']);

        return $this->byTenant[$tenantId] = $fields;
    }
}
