<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

trait WithConversationContext
{
    protected ?string $conversationId = null;

    protected ?string $turnId = null;

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    /**
     * The turn every proposal from this tool belongs to. Proposals sharing a turn
     * id are the steps of one plan, which is how a chained multi-step write
     * renders as a single approval card.
     */
    public function setTurnId(?string $turnId): self
    {
        $this->turnId = $turnId === '' ? null : $turnId;

        return $this;
    }

    protected function resolveConversationId(): ?string
    {
        return $this->conversationId;
    }

    protected function resolveTurnId(): ?string
    {
        return $this->turnId;
    }
}
