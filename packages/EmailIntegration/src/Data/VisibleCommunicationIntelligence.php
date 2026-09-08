<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Enums\ConnectionStrength;

final readonly class VisibleCommunicationIntelligence
{
    public function __construct(
        public int $emailCount = 0,
        public ?Carbon $firstEmailAt = null,
        public ?Carbon $lastEmailAt = null,
        public ?Carbon $firstMeetingAt = null,
        public ?Carbon $lastMeetingAt = null,
        public ?Carbon $nextMeetingAt = null,
        public ConnectionStrength $connectionStrength = ConnectionStrength::None,
        public ?string $strongestConnectionName = null,
    ) {}

    public function firstInteractionAt(): ?Carbon
    {
        return $this->earlier($this->firstEmailAt, $this->firstMeetingAt);
    }

    public function lastInteractionAt(): ?Carbon
    {
        if (! $this->lastEmailAt instanceof Carbon) {
            return $this->lastMeetingAt;
        }

        if (! $this->lastMeetingAt instanceof Carbon) {
            return $this->lastEmailAt;
        }

        return $this->lastEmailAt->greaterThan($this->lastMeetingAt)
            ? $this->lastEmailAt
            : $this->lastMeetingAt;
    }

    private function earlier(?Carbon $left, ?Carbon $right): ?Carbon
    {
        if (! $left instanceof Carbon) {
            return $right;
        }

        if (! $right instanceof Carbon) {
            return $left;
        }

        return $left->lessThan($right) ? $left : $right;
    }
}
