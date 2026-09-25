<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatically sets creator_id and workspace_id on model creation
 * when an authenticated user is present.
 *
 * @mixin Model
 */
trait BelongsToWorkspaceCreator
{
    public static function bootBelongsToWorkspaceCreator(): void
    {
        static::creating(function (self $model): void {
            if (auth()->check()) {
                /** @var User $user */
                $user = auth()->user();
                $model->creator_id ??= $user->getKey();
                $model->workspace_id ??= $user->currentWorkspace->getKey();
            }
        });
    }
}
