<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Carbon\CarbonImmutable;

final readonly class UnlinkedSender
{
    public function __construct(
        public string $address,
        public ?string $name,
        public int $conversationCount,
        public CarbonImmutable $lastMessageAt,
        public int $sentToCount,
    ) {}

    public function displayName(): string
    {
        return filled($this->name) ? $this->name : $this->address;
    }
}
