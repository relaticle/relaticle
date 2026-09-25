<?php

declare(strict_types=1);

namespace App\Data;

final readonly class DigestWorkspaceSection
{
    /**
     * @param  list<DigestTaskItem>  $overdue
     * @param  list<DigestTaskItem>  $upcoming
     */
    public function __construct(
        public string $workspaceName,
        public array $overdue,
        public array $upcoming,
    ) {}

    public function isEmpty(): bool
    {
        return $this->overdue === [] && $this->upcoming === [];
    }
}
