<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's rating of one assistant message — the quality signal pipeline.
 *
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string $conversation_id
 * @property string $message_id
 * @property string $rating
 * @property ?string $category
 * @property ?string $comment
 * @property ?string $model
 */
final class ChatMessageFeedback extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlids;

    public const string RATING_UP = 'up';

    public const string RATING_DOWN = 'down';

    public const array CATEGORIES = ['inaccurate', 'did_not_follow', 'too_slow', 'other'];

    protected $table = 'chat_message_feedback';

    protected $fillable = [
        'team_id',
        'user_id',
        'conversation_id',
        'message_id',
        'rating',
        'category',
        'comment',
        'model',
    ];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<AgentConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }
}
