<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\Concerns\HasWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property int $credits_remaining
 * @property int $credits_used
 * @property int $purchased_credits
 * @property CarbonImmutable $period_starts_at
 * @property CarbonImmutable $period_ends_at
 */
#[Fillable([
    'workspace_id',
    'credits_remaining',
    'credits_used',
    'purchased_credits',
    'period_starts_at',
    'period_ends_at',
])]
final class AiCreditBalance extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credits_remaining' => 'integer',
            'credits_used' => 'integer',
            'purchased_credits' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }
}
