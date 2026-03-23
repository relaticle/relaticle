<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'abilities',
        'expires_at',
        'team_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalAccessToken $token): void {
            if ($token->team_id && $token->tokenable instanceof User) {
                abort_unless(
                    $token->tokenable->belongsToTeam(Team::query()->find($token->team_id)),
                    403,
                    'Token team_id must belong to the tokenable user.',
                );
            }
        });

        static::updating(function (PersonalAccessToken $token): void {
            throw_if($token->isDirty('team_id') && $token->getOriginal('team_id') !== null, \LogicException::class, 'The team_id attribute cannot be changed after it has been set.');
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
