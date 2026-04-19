<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Support;

final readonly class ActivityLogDiffRow
{
    public function __construct(
        public string $label,
        public mixed $old,
        public mixed $new,
    ) {}

    public function formattedOld(): string
    {
        return AttributeFormatter::format($this->old);
    }

    public function formattedNew(): string
    {
        return AttributeFormatter::format($this->new);
    }
}
