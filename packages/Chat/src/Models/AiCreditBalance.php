<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\Concerns\HasTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $team_id
 * @property int $credits_remaining
 * @property int $credits_used
 * @property Carbon $period_starts_at
 * @property Carbon $period_ends_at
 */
#[Fillable([
    'team_id',
    'credits_remaining',
    'credits_used',
    'period_starts_at',
    'period_ends_at',
])]
final class AiCreditBalance extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasTeam;
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credits_remaining' => 'integer',
            'credits_used' => 'integer',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }
}
