<?php

declare(strict_types=1);

namespace App\Queries\Views;

use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

final readonly class ViewOrderQuery
{
    public function __construct(private StoredTablePreferenceQuery $preferences) {}

    /**
     * @param  list<string>  $kinds
     * @param  Collection<int, SavedView>  $views
     * @return list<string>
     */
    public function get(User $user, Workspace $workspace, CrmEntity $entity, array $kinds, Collection $views): array
    {
        $natural = [
            ...array_filter($entity->builtInViews(), static fn (string $kind): bool => in_array($kind, $kinds, true)),
            ...$views->sortBy([['created_at', 'asc'], ['id', 'asc']])->map(static fn (SavedView $view): string => (string) $view->getKey())->values()->all(),
        ];
        $stored = $this->preferences->get($user, $workspace, $entity)->view_order ?? [];

        return array_values(array_unique([...array_intersect($stored, $natural), ...$natural]));
    }
}
