<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Referral\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\Cloud\Referral\Enums\ReferralPromptOutcome;

/**
 * @property ReferralPromptOutcome $outcome
 * @property CarbonImmutable $ended_at
 */
#[Table(key: 'user_id', keyType: 'string', incrementing: false, timestamps: false)]
#[Unguarded]
final class ReferralPrompt extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => ReferralPromptOutcome::class,
            'ended_at' => 'datetime',
        ];
    }
}
