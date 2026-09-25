<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\ChatAttachment;
use Relaticle\Chat\Support\TitleSanitizer;

final readonly class CreateConversation
{
    // An upload made before the first message already opened a conversation;
    // the message joins it instead of opening a second one.
    public function execute(User $user, Workspace $workspace, string $title, ?ChatAttachment $attachment = null): AgentConversation
    {
        $opened = $attachment?->conversation();

        if ($opened instanceof AgentConversation && ! $opened->messages()->exists()) {
            $opened->update(['title' => TitleSanitizer::clean($title)]);

            return $opened;
        }

        return AgentConversation::query()->create([
            'id' => (string) Str::uuid7(),
            'participant_type' => $user->getMorphClass(),
            'participant_id' => (string) $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'title' => TitleSanitizer::clean($title),
        ]);
    }
}
