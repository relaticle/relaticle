<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $participant_type
 * @property string|null $participant_id
 * @property string|null $workspace_id
 * @property string|null $title
 * @property string|null $purpose
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'agent_conversations', keyType: 'string')]
#[WithoutIncrementing]
final class AgentConversation extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    public const string PURPOSE_SETUP = 'setup';

    protected $guarded = [];

    public function isSetup(): bool
    {
        return $this->purpose === self::PURPOSE_SETUP;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<AgentConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function setup(Builder $query): void
    {
        $query->where('purpose', self::PURPOSE_SETUP);
    }
}
