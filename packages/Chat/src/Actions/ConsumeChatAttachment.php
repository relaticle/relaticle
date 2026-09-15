<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use Relaticle\Chat\Support\ChatAttachment;

final readonly class ConsumeChatAttachment
{
    public function execute(ChatAttachment $attachment, string $conversationId): void
    {
        $attachment->media
            ->setCustomProperty('conversation_id', $conversationId)
            ->setCustomProperty('consumed_at', now()->toIso8601String())
            ->save();
    }
}
