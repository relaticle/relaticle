<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\PersonalAccessTokenObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use LogicException;

/** Cannot be final — Sanctum::actingAs() uses Mockery to mock this class in tests */
#[ObservedBy(PersonalAccessTokenObserver::class)]
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'abilities',
        'expires_at',
        'team_id',
    ];

    protected static function booted(): void
    {
        self::creating(function (PersonalAccessToken $token): void {
            if ($token->team_id && $token->tokenable instanceof User) {
                abort_unless(
                    $token->tokenable->belongsToTeam(Team::query()->find($token->team_id)),
                    403,
                    'Token team_id must belong to the tokenable user.',
                );
            }
        });

        self::updating(function (PersonalAccessToken $token): void {
            if ($token->isDirty('team_id')) {
                throw_if($token->getOriginal('team_id') !== null, LogicException::class, 'The team_id attribute cannot be changed after it has been set.');

                if ($token->team_id && $token->tokenable instanceof User) {
                    abort_unless(
                        $token->tokenable->belongsToTeam(Team::query()->find($token->team_id)),
                        403,
                        'Token team_id must belong to the tokenable user.',
                    );
                }
            }
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
