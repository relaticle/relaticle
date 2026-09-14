<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Carbon\CarbonInterface;
use Relaticle\EmailIntegration\Enums\ConnectionStrength;

final readonly class VisibleCommunicationIntelligence
{
    public function __construct(
        public int $emailCount = 0,
        public ?CarbonInterface $firstEmailAt = null,
        public ?CarbonInterface $lastEmailAt = null,
        public ?CarbonInterface $firstMeetingAt = null,
        public ?CarbonInterface $lastMeetingAt = null,
        public ?CarbonInterface $nextMeetingAt = null,
        public ConnectionStrength $connectionStrength = ConnectionStrength::None,
        public ?string $strongestConnectionName = null,
    ) {}

    public function firstInteractionAt(): ?CarbonInterface
    {
        return $this->earlier($this->firstEmailAt, $this->firstMeetingAt);
    }

    public function lastInteractionAt(): ?CarbonInterface
    {
        if (! $this->lastEmailAt instanceof CarbonInterface) {
            return $this->lastMeetingAt;
        }

        if (! $this->lastMeetingAt instanceof CarbonInterface) {
            return $this->lastEmailAt;
        }

        return $this->lastEmailAt->greaterThan($this->lastMeetingAt)
            ? $this->lastEmailAt
            : $this->lastMeetingAt;
    }

    private function earlier(?CarbonInterface $left, ?CarbonInterface $right): ?CarbonInterface
    {
        if (! $left instanceof CarbonInterface) {
            return $right;
        }

        if (! $right instanceof CarbonInterface) {
            return $left;
        }

        return $left->lessThan($right) ? $left : $right;
    }
}
