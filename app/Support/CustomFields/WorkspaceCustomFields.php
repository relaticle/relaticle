<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomField;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class WorkspaceCustomFields
{
    /** @var array<string, Collection<int, CustomField>> */
    private array $byTenant = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $derived = [];

    /**
     * @return Collection<int, CustomField>
     */
    public function forEntity(Workspace $workspace, string $entityType): Collection
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

    public function forget(int|string $tenantId): void
    {
        unset($this->byTenant[(string) $tenantId], $this->derived[(string) $tenantId]);
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function all(Workspace $workspace): Collection
    {
        $tenantId = (string) $workspace->getKey();

        return $this->byTenant[$tenantId] ??= CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['options' => static fn (HasMany $options) => $options->withoutGlobalScopes()->where('tenant_id', $tenantId)])
            ->get();
    }
}
