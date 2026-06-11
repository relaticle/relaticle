<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enumerates the workspace's members for the assistant's context. Team-member
 * fields (company account owner, task assignees) accept ONLY these users —
 * without this list the model cannot resolve a name to a user id and falls
 * back to denying the field exists (observed in production).
 */
final readonly class TeamMembersContext
{
    private const int MAX_MEMBERS = 50;

    /** @return list<array{id: string, name: string, email: string}> */
    public static function for(User $user, ?string $search = null): array
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return [];
        }

        $memberIds = $team->users()->pluck('users.id')->all();
        $memberIds[] = $team->user_id;

        return array_values(User::query()
            ->whereIn('id', array_unique(array_map(strval(...), $memberIds)))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $pattern = '%'.LikePattern::escape((string) $search).'%';
                $query->where(function (Builder $inner) use ($pattern): void {
                    $inner->whereLike('name', $pattern)->orWhereLike('email', $pattern);
                });
            })
            ->orderBy('name')
            ->limit(self::MAX_MEMBERS)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $member): array => [
                'id' => (string) $member->getKey(),
                'name' => (string) $member->name,
                'email' => (string) $member->email,
            ])
            ->all());
    }

    public static function isMember(User $user, string $candidateId): bool
    {
        return array_any(self::for($user), fn (array $member): bool => $member['id'] === $candidateId);
    }

    public static function describeList(User $user): string
    {
        $names = array_map(
            fn (array $member): string => "{$member['name']} ({$member['email']})",
            self::for($user),
        );

        return implode(', ', $names);
    }
}
