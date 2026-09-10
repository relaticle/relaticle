<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\AsCanonicalEmail;
use App\Enums\EmailChallengePurpose;
use App\Support\Auth\AppKey;
use Carbon\CarbonImmutable;
use Database\Factories\EmailChallengeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $flow_id
 * @property int $generation
 * @property EmailChallengePurpose $purpose
 * @property string $email
 * @property string|null $user_id
 * @property string $flow_digest
 * @property string $code_digest
 * @property string|null $state_fingerprint
 * @property string|null $operation_id
 * @property CarbonImmutable $expires_at
 * @property int $failed_attempts
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $invalidated_at
 * @property string|null $invalidation_reason
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
final class EmailChallenge extends Model
{
    /** @use HasFactory<EmailChallengeFactory> */
    use HasFactory;

    use HasUlids;

    public const UPDATED_AT = null;

    public const int MAX_FAILED_ATTEMPTS = 5;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return $this->consumed_at !== null
            || $this->invalidated_at !== null
            || $this->expires_at->isPast()
            || $this->failed_attempts >= self::MAX_FAILED_ATTEMPTS;
    }

    /**
     * Keyed so a leaked database can't brute-force six-digit codes offline.
     * Binds the generation row's own id, not flow_id.
     */
    public static function hashCode(string $challengeId, EmailChallengePurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$purpose->value.'|'.$code, self::hmacKey());
    }

    /**
     * Digest of a random secret unrelated to the public challenge id or the
     * recipient email, so neither can supply it.
     */
    public static function hashFlowSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, self::hmacKey());
    }

    /**
     * Snapshots the account state an authenticated challenge is bound to, so
     * a later change to that state (e.g. the email itself) can be detected.
     */
    public static function stateFingerprint(User $user): string
    {
        return hash_hmac('sha256', (string) $user->getRawOriginal('email'), self::hmacKey());
    }

    private static function hmacKey(): string
    {
        return hash_hkdf('sha256', AppKey::decode(), 32, 'relaticle.auth.email-code.v1');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => EmailChallengePurpose::class,
            'email' => AsCanonicalEmail::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
