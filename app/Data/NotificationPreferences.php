<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Notifications\DigestCadence;
use Spatie\LaravelData\Data;

final class NotificationPreferences extends Data
{
    public function __construct(
        public bool $taskAssignedInApp = true,
        public bool $taskAssignedEmail = false,
        public DigestCadence $digestCadence = DigestCadence::Daily,
    ) {}

    public function withDigestCadence(DigestCadence $cadence): self
    {
        return new self(
            taskAssignedInApp: $this->taskAssignedInApp,
            taskAssignedEmail: $this->taskAssignedEmail,
            digestCadence: $cadence,
        );
    }
}
