<?php

declare(strict_types=1);

namespace App\Data;

final readonly class DigestPayload
{
    /**
     * @param  list<DigestWorkspaceSection>  $workspaces
     */
    public function __construct(
        public array $workspaces,
    ) {}

    public function isEmpty(): bool
    {
        return array_all($this->workspaces, fn (DigestWorkspaceSection $workspace): bool => $workspace->isEmpty());
    }

    public function taskCount(): int
    {
        $count = 0;

        foreach ($this->workspaces as $workspace) {
            $count += count($workspace->overdue) + count($workspace->upcoming);
        }

        return $count;
    }

    public function overdueCount(): int
    {
        return array_sum(array_map(fn (DigestWorkspaceSection $workspace): int => count($workspace->overdue), $this->workspaces));
    }

    public function upcomingCount(): int
    {
        return array_sum(array_map(fn (DigestWorkspaceSection $workspace): int => count($workspace->upcoming), $this->workspaces));
    }
}
