<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

/**
 * @property ?Carbon $expires_at
 * @property ?string $token
 * @property ?string $inviter_id
 */
#[Fillable([
    'email',
    'role',
    'expires_at',
    'inviter_id',
    'token',
])]
final class TeamInvitation extends JetstreamTeamInvitation
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return true;
        }

        /** @var Carbon $expiresAt */
        $expiresAt = $this->expires_at;

        return $expiresAt->isPast();
    }

    /**
     * Mint a fresh raw token, store its hash and a renewed expiry window on the
     * model (the caller persists), and return the raw token for delivery by mail.
     * Single source of truth for the token and expiry rules shared by the invite
     * and resend paths.
     */
    public function issueToken(): string
    {
        $rawToken = Str::random(40);

        $this->token = hash('sha256', $rawToken);
        $this->expires_at = now()->addDays((int) config('jetstream.invitation_expiry_days', 7));

        return $rawToken;
    }

    public static function findByRawToken(string $rawToken): ?self
    {
        return self::query()->where('token', hash('sha256', $rawToken))->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
