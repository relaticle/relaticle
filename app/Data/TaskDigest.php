<?php

declare(strict_types=1);

namespace App\Data;

final readonly class TaskDigest
{
    /**
     * @param  list<DigestTaskItem>  $overdue
     * @param  list<DigestTaskItem>  $upcoming
     */
    public function __construct(
        public array $overdue,
        public array $upcoming,
    ) {}

    public function isEmpty(): bool
    {
        return $this->overdue === [] && $this->upcoming === [];
    }

    public function overdueCount(): int
    {
        return count($this->overdue);
    }

    public function upcomingCount(): int
    {
        return count($this->upcoming);
    }
}
