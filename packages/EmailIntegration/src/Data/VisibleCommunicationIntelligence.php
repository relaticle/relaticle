<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Carbon;

final readonly class VisibleCommunicationIntelligence
{
    public function __construct(
        public int $emailCount = 0,
        public int $inboundEmailCount = 0,
        public int $outboundEmailCount = 0,
        public ?Carbon $lastEmailAt = null,
        public ?Carbon $lastMeetingAt = null,
    ) {}

    public function lastInteractionAt(): ?Carbon
    {
        if ($this->lastEmailAt === null) {
            return $this->lastMeetingAt;
        }

        if ($this->lastMeetingAt === null) {
            return $this->lastEmailAt;
        }

        return $this->lastEmailAt->greaterThan($this->lastMeetingAt)
            ? $this->lastEmailAt
            : $this->lastMeetingAt;
    }
}
