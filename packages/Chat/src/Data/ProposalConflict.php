<?php

declare(strict_types=1);

namespace Relaticle\Chat\Data;

use Carbon\CarbonImmutable;

final readonly class ProposalConflict
{
    public function __construct(
        public string $activityId,
        public string $key,
        public string $field,
        public ?string $value,
        public ?string $causerId,
        public string $causerName,
        public CarbonImmutable $at,
    ) {}
}
