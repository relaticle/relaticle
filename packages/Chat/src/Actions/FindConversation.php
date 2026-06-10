<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class FindConversation
{
    public function execute(User $user, string $conversationId): ?\stdClass
    {
        return DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->current_team_id)
            ->first(['id', 'title', 'created_at', 'updated_at']);
    }
}
