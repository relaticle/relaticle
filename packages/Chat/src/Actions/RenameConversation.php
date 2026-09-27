<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\TitleSanitizer;
use RuntimeException;

final readonly class RenameConversation
{
    public function execute(User $user, string $conversationId, string $title): string
    {
        throw_unless(
            AgentConversation::query()->ownedBy($user)->whereKey($conversationId)->exists(),
            RuntimeException::class,
            'Conversation not found.',
        );

        $sanitized = TitleSanitizer::clean($title);

        DB::table('agent_conversations')
            ->where('id', $conversationId)
            ->update(['title' => $sanitized, 'updated_at' => now()]);

        return $sanitized;
    }
}
