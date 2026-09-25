<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PersonalAccessTokenObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use LogicException;

/** Cannot be final; Sanctum::actingAs() uses Mockery to mock this class in tests */
#[ObservedBy(PersonalAccessTokenObserver::class)]
#[\Illuminate\Database\Eloquent\Attributes\Fillable([
    'name',
    'abilities',
    'expires_at',
    'workspace_id',
])]
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected static function booted(): void
    {
        self::creating(function (PersonalAccessToken $token): void {
            if ($token->workspace_id && $token->tokenable instanceof User) {
                abort_unless(
                    $token->tokenable->belongsToWorkspaceId($token->workspace_id),
                    403,
                    'Token workspace_id must belong to the tokenable user.',
                );
            }
        });

        self::updating(function (PersonalAccessToken $token): void {
            if ($token->isDirty('workspace_id')) {
                throw_if($token->getOriginal('workspace_id') !== null, LogicException::class, 'The workspace_id attribute cannot be changed after it has been set.');

                if ($token->workspace_id && $token->tokenable instanceof User) {
                    abort_unless(
                        $token->tokenable->belongsToWorkspaceId($token->workspace_id),
                        403,
                        'Token workspace_id must belong to the tokenable user.',
                    );
                }
            }
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
