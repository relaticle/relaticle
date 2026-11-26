<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Data;

final readonly class BillingSummary
{
    public function __construct(
        public string $plan,
        public string $status,
        public string $tone,
        public string $headline,
        public ?string $card,
        public ?string $refreshSubscriptionId,
    ) {}
}
