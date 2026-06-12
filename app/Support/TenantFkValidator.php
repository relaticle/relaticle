<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final readonly class TenantFkValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, class-string<Model>>  $fkToModelMap
     */
    public static function assertOwned(User $user, array $data, array $fkToModelMap): void
    {
        $teamId = $user->current_team_id;

        if ($teamId === null) {
            throw ValidationException::withMessages(['team' => 'No active workspace.']);
        }

        foreach ($fkToModelMap as $field => $modelClass) {
            $value = $data[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                continue;
            }

            $owned = $modelClass::query()
                ->where('team_id', $teamId)
                ->whereKey($value)
                ->exists();

            if (! $owned) {
                throw ValidationException::withMessages([
                    $field => "Referenced {$field} is not in your workspace.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, class-string<Model>>  $fkArrayToModelMap
     */
    public static function assertOwnedMany(User $user, array $data, array $fkArrayToModelMap): void
    {
        $teamId = $user->current_team_id;

        if ($teamId === null) {
            throw ValidationException::withMessages(['team' => 'No active workspace.']);
        }

        foreach ($fkArrayToModelMap as $field => $modelClass) {
            $values = $data[$field] ?? null;
            if (! is_array($values)) {
                continue;
            }
            if ($values === []) {
                continue;
            }

            $unique = array_values(array_unique(array_map(strval(...), $values)));

            $owned = $modelClass::query()
                ->where('team_id', $teamId)
                ->whereIn((new $modelClass)->getKeyName(), $unique)
                ->count();

            if ($owned !== count($unique)) {
                throw ValidationException::withMessages([
                    $field => "One or more {$field} are not in your workspace.",
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    public static function assertUsersInWorkspace(User $user, array $data, array $fields): void
    {
        $team = $user->currentTeam;

        if ($team === null) {
            throw ValidationException::withMessages(['team' => 'No active workspace.']);
        }

        $memberIds = $team->users()->pluck('users.id')->all();
        $memberIds[] = $team->user_id;
        $memberIds = array_map(strval(...), $memberIds);

        foreach ($fields as $field) {
            $values = $data[$field] ?? null;
            if (! is_array($values)) {
                continue;
            }
            if ($values === []) {
                continue;
            }

            foreach ($values as $value) {
                if (! in_array((string) $value, $memberIds, true)) {
                    throw ValidationException::withMessages([
                        $field => 'One or more assignees are not in your workspace.',
                    ]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     */
    public static function assertUserInWorkspace(User $user, array $data, array $fields): void
    {
        $supplied = array_filter($fields, fn (string $field): bool => ($data[$field] ?? null) !== null && ($data[$field] ?? null) !== '');

        if ($supplied === []) {
            return;
        }

        $team = $user->currentTeam;

        if ($team === null) {
            throw ValidationException::withMessages(['team' => 'No active workspace.']);
        }

        $memberIds = $team->users()->pluck('users.id')->all();
        $memberIds[] = $team->user_id;
        $memberIds = array_map(strval(...), $memberIds);

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                continue;
            }

            if (! in_array((string) $value, $memberIds, true)) {
                throw ValidationException::withMessages([
                    $field => "Referenced {$field} is not a member of your workspace.",
                ]);
            }
        }
    }
}
