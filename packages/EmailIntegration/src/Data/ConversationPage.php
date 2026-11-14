<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

final readonly class ConversationPage
{
    /**
     * @param  list<ConversationRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
        public ?string $previousCursor,
    ) {}
}
