<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Read model over the laravel/ai message store. Backs the SystemAdmin
 * Messages resource. Writes happen through laravel/ai's own persistence.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string|null $participant_type
 * @property string|null $participant_id
 * @property string|null $agent
 * @property string $role
 * @property string|null $content
 * @property Carbon|null $superseded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'agent_conversation_messages', keyType: 'string')]
#[WithoutIncrementing]
final class AgentConversationMessage extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AgentConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function sentBy(Builder $query, User $user): void
    {
        $query->where('participant_type', $user->getMorphClass())
            ->where('participant_id', (string) $user->getKey())
            ->where('role', 'user');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }
}
