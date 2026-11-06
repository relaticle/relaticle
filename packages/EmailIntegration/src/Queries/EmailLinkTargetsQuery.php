<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\LikePattern;

final readonly class EmailLinkTargetsQuery
{
    /** @return list<string> */
    public static function types(): array
    {
        return array_map(fn (CrmEntity $entity): string => $entity->value, CrmEntity::linkable());
    }

    public function find(User $viewer, string $type, string $id): Company|People|Opportunity|null
    {
        $entity = $this->linkable($type);

        if (! $entity instanceof CrmEntity || $id === '') {
            return null;
        }

        $record = $entity->model()::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $viewer->current_workspace_id)
            ->find($id);

        return $record instanceof Company || $record instanceof People || $record instanceof Opportunity ? $record : null;
    }

    /** @return array<string, string> */
    public function search(User $viewer, string $type, string $search): array
    {
        $entity = $this->linkable($type);

        if (! $entity instanceof CrmEntity) {
            return [];
        }

        return $entity->model()::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $viewer->current_workspace_id)
            ->whereLike($entity->titleColumn(), '%'.LikePattern::escape($search).'%')
            ->orderBy($entity->titleColumn())
            ->limit(20)
            ->pluck($entity->titleColumn(), 'id')
            ->map(fn (mixed $name): string => (string) $name)
            ->all();
    }

    private function linkable(string $type): ?CrmEntity
    {
        $entity = CrmEntity::tryFrom($type);

        return in_array($entity, CrmEntity::linkable(), true) ? $entity : null;
    }
}
