<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\People;

final readonly class MassSendRecipientResult
{
    /**
     * @param  list<array{person: People, email: string}>  $recipients
     */
    public function __construct(
        public array $recipients,
        public int $skipped,
    ) {}
}
