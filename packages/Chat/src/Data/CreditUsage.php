<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

use Carbon\CarbonImmutable;

final readonly class CreditUsage
{
    /**
     * @param  array<string, int>  $daily  credits per local day (Y-m-d), every day from $since to today
     * @param  array<string, int>  $byMember  credits per user id, largest first
     * @param  array<string, int>  $byModel  credits per model id, largest first
     */
    public function __construct(
        public CarbonImmutable $since,
        public int $total,
        public array $daily,
        public array $byMember,
        public array $byModel,
    ) {}
}
