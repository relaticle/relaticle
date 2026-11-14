<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\EmailConversationStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $conversation_key
 * @property CarbonImmutable $done_at
 * @property CarbonImmutable|null $done_through
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table('email_conversation_states')]
#[Fillable(['workspace_id', 'user_id', 'conversation_key', 'done_at', 'done_through'])]
final class EmailConversationState extends Model
{
    /**
     * @use HasFactory<EmailConversationStateFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    protected static function newFactory(): EmailConversationStateFactory
    {
        return EmailConversationStateFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'done_at' => 'immutable_datetime',
            'done_through' => 'immutable_datetime',
        ];
    }
}
