<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\Comments\CommentsConfig;
use Relaticle\Comments\Contracts\MentionResolver;

/**
 * Scopes @mention autocomplete and mention resolution to members of the
 * current workspace — the package default searches the whole users table,
 * which would leak user names across tenants.
 */
final readonly class TeamMentionResolver implements MentionResolver
{
    /** @return Collection<int, Model> */
    public function search(string $query): Collection
    {
        $pattern = addcslashes($query, '\\%_').'%';

        return $this->teamMembers()
            ->whereLike('name', $pattern)
            ->orderBy('name')
            ->limit(CommentsConfig::getMentionMaxResults())
            ->get()
            ->map(fn (Model $user): Model => $user);
    }

    /**
     * @param  array<int, string>  $names
     * @return Collection<int, Model>
     */
    public function resolveByNames(array $names): Collection
    {
        return $this->teamMembers()
            ->whereIn('name', $names)
            ->get()
            ->map(fn (Model $user): Model => $user);
    }

    /** @return Builder<User> */
    private function teamMembers(): Builder
    {
        $team = Filament::getTenant();

        if (! $team instanceof Team) {
            return User::query()->whereKey([]);
        }

        $memberIds = $team->users()->pluck('users.id')->push($team->user_id);

        return User::query()->whereKey($memberIds->unique()->all());
    }
}
