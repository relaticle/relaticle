<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class InboxRequest
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public ?string $body,
        public ?User $requester,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $expiresAt,
        public ?string $url,
    ) {}
}
