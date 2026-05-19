<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

trait WithConversationContext
{
    protected ?string $conversationId = null;

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    protected function resolveConversationId(): ?string
    {
        return $this->conversationId;
    }
}
