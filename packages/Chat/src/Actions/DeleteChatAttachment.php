<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\ChatAttachment;

final readonly class DeleteChatAttachment
{
    public function __construct(private DeleteConversation $conversations) {}

    public function execute(User $user, string $attachmentId): bool
    {
        $attachment = ChatAttachment::find($user, $attachmentId);

        if (! $attachment instanceof ChatAttachment || $attachment->isSent()) {
            return false;
        }

        return DB::transaction(function () use ($user, $attachment): bool {
            $conversation = $attachment->conversation();

            $attachment->media->delete();

            if ($conversation instanceof AgentConversation && ! $conversation->isSetup() && ! $conversation->messages()->exists()) {
                $this->conversations->execute($user, (string) $conversation->getKey());
            }

            return true;
        });
    }
}
