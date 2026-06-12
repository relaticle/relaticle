<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Workspace member directory for the chat tools: backs ListTeamMembersTool and
 * validates team-member fields (company account_owner_id, task assignee_ids),
 * which accept ONLY workspace users — never CRM contacts.
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

    /**
     * Validate a team-member field value at proposal time: null and '' pass
     * (absent / unassign), a scalar id or list of ids must all be workspace
     * members — checked with one exact query, never the display-capped list.
     * Returns the model-facing error naming the valid members, or null.
     */
    public static function memberFieldError(User $user, string $field, mixed $value): ?string
    {
        $ids = match (true) {
            $value === null, $value === '' => [],
            is_array($value) => array_values(array_filter(
                array_map(strval(...), $value),
                fn (string $id): bool => $id !== '',
            )),
            default => [(string) $value],
        };

        if ($ids === []) {
            return null;
        }

        $team = $user->currentTeam;

        if ($team === null) {
            return "{$field}: no active workspace.";
        }

        $known = $team->users()->whereKey($ids)->pluck('users.id')->map(strval(...))->all();
        $known[] = (string) $team->user_id;

        if (array_diff($ids, $known) === []) {
            return null;
        }

        return "{$field} must be a workspace team member. Valid members: "
            .self::describeList($user)
            .'. Contacts/people records are not valid for this field.';
    }

    public static function nameOf(string $userId): ?string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return $name === null ? null : (string) $name;
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
