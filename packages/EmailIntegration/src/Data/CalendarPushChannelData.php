<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Illuminate\Support\Carbon;

final readonly class CalendarPushChannelData
{
    public function __construct(
        public string $channelId,
        public ?string $resourceId,
        public string $verificationToken,
        public Carbon $expiresAt,
    ) {}
}
