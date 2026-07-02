<?php

declare(strict_types=1);

namespace App\Data;

final readonly class DigestPayload
{
    /**
     * @param  list<DigestTeamSection>  $teams
     */
    public function __construct(
        public array $teams,
    ) {}

    public function isEmpty(): bool
    {
        return array_all($this->teams, fn (DigestTeamSection $team): bool => $team->isEmpty());
    }

    public function taskCount(): int
    {
        $count = 0;

        foreach ($this->teams as $team) {
            $count += count($team->overdue) + count($team->upcoming);
        }

        return $count;
    }
}
