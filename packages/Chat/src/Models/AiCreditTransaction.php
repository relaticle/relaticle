<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\Chat\Enums\AiCreditType;

/**
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string|null $conversation_id
 * @property string|null $idempotency_key
 * @property AiCreditType $type
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $credits_charged
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'team_id',
    'user_id',
    'conversation_id',
    'idempotency_key',
    'type',
    'model',
    'input_tokens',
    'output_tokens',
    'credits_charged',
    'metadata',
    'created_at',
])]
final class AiCreditTransaction extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasTeam;
    use HasUlids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AiCreditType::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'credits_charged' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
