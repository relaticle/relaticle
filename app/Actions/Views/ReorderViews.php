<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\VisibleSavedViewsQuery;

final readonly class ReorderViews
{
    public function __construct(private VisibleSavedViewsQuery $views) {}

    /**
     * @param  list<mixed>  $keys
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array $keys): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);
        abort_unless(array_all($keys, static fn (mixed $key): bool => is_string($key)), 403);

        $keys = array_values(array_unique($keys));
        $allowed = [...$entity->builtInViews(), ...$this->views->get($user, $workspace, $entity)->modelKeys()];

        abort_if(array_diff($keys, $allowed) !== [], 403);

        $preference = TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->firstOrCreate(
                ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                ['state' => new TableStateData],
            );
        $preference->update(['view_order' => $this->keepUnpostedInPlace($preference->view_order ?? [], $keys, $allowed)]);

        return $preference;
    }

    /**
     * @param  list<string>  $stored
     * @param  list<string>  $posted
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function keepUnpostedInPlace(array $stored, array $posted, array $allowed): array
    {
        $queue = $posted;
        $order = [];

        foreach (array_filter($stored, static fn (string $key): bool => in_array($key, $allowed, true)) as $key) {
            $order[] = in_array($key, $posted, true) ? array_shift($queue) : $key;
        }

        return array_values(array_unique([...$order, ...$queue]));
    }
}
