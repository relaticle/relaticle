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
        $workspaceId = $user->current_workspace_id;

        if ($workspaceId === null) {
            throw ValidationException::withMessages(['workspace' => 'No active workspace.']);
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
                ->where('workspace_id', $workspaceId)
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
        $workspaceId = $user->current_workspace_id;

        if ($workspaceId === null) {
            throw ValidationException::withMessages(['workspace' => 'No active workspace.']);
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
                ->where('workspace_id', $workspaceId)
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
        $workspace = $user->currentWorkspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['workspace' => 'No active workspace.']);
        }

        $memberIds = array_map(strval(...), User::query()->memberOf($workspace)->pluck('id')->all());

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

        $workspace = $user->currentWorkspace;

        if ($workspace === null) {
            throw ValidationException::withMessages(['workspace' => 'No active workspace.']);
        }

        $memberIds = array_map(strval(...), User::query()->memberOf($workspace)->pluck('id')->all());

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
