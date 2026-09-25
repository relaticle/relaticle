<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use Relaticle\Chat\Support\ChatAttachment;

final readonly class MarkAttachmentSent
{
    public function execute(ChatAttachment $attachment): void
    {
        $attachment->media
            ->setCustomProperty('sent_at', now()->toIso8601String())
            ->save();
    }
}
