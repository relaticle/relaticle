<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Relaticle\Chat\Models\AgentConversation;

final readonly class FindConversation
{
    public function execute(User $user, string $conversationId): ?\stdClass
    {
        return AgentConversation::query()
            ->ownedBy($user)
            ->whereKey($conversationId)
            ->toBase()
            ->first(['id', 'title', 'created_at', 'updated_at']);
    }
}
