<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CrmEntity;

final readonly class DigestRecord
{
    public function __construct(
        public CrmEntity $entity,
        public string $name,
    ) {}
}
