<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Models;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable|null $first_paid_at
 */
#[Table(key: 'user_id', keyType: 'string', incrementing: false, timestamps: false)]
#[Unguarded]
final class Acquisition extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'workspace_linked_at' => 'datetime',
            'setup_completed_at' => 'datetime',
            'first_checkout_at' => 'datetime',
            'first_paid_at' => 'datetime',
            'first_renewal_failed_at' => 'datetime',
        ];
    }
}
