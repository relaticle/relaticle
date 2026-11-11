<?php

declare(strict_types=1);

namespace App\Queries\Views;

use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

final readonly class VisibleSavedViewsQuery
{
    /**
     * @return Collection<int, SavedView>
     */
    public function get(User $user, Workspace $workspace, CrmEntity $entity): Collection
    {
        return SavedView::query()
            ->visibleTo($user, (string) $workspace->getKey(), $entity)
            ->orderByRaw('lower(name)')
            ->get();
    }
}
