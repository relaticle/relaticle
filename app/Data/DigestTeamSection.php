<?php

declare(strict_types=1);

namespace App\Data;

final readonly class DigestTeamSection
{
    /**
     * @param  list<DigestTaskItem>  $overdue
     * @param  list<DigestTaskItem>  $upcoming
     */
    public function __construct(
        public string $teamName,
        public array $overdue,
        public array $upcoming,
    ) {}

    public function isEmpty(): bool
    {
        return $this->overdue === [] && $this->upcoming === [];
    }
}
