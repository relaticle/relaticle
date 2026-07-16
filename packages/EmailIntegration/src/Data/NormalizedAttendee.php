<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;

final readonly class NormalizedAttendee
{
    public function __construct(
        public string $emailAddress,
        public ?string $name,
        public ?AttendeeResponseStatus $responseStatus,
        public bool $isOrganizer,
        public bool $isSelf,
    ) {}
}
