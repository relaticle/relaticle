<?php

declare(strict_types=1);

namespace App\Data;

final readonly class SubscriptionEnding
{
    public function __construct(
        public string $notice,
        public string $keepLabel,
        public string $heading,
        public string $keepUrl,
        public string $terms,
        public string $keptMessage,
    ) {}
}
