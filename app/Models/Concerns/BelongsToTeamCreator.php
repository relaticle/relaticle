<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatically sets creator_id and team_id on model creation
 * when an authenticated user is present.
 *
 * @mixin Model
 */
trait BelongsToTeamCreator
{
    public static function bootBelongsToTeamCreator(): void
    {
        static::creating(function (self $model): void {
            if (auth()->check()) {
                /** @var User $user */
                $user = auth()->user();
                $model->creator_id ??= $user->getKey();
                $model->team_id ??= $user->currentTeam->getKey();
            }
        });
    }
}
