<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Data;

use Carbon\CarbonInterface;

final readonly class ConnectAccountData
{
    public function __construct(
        public string $userId,
        public string $teamId,
        public string $provider,
        public string $emailAddress,
        public ?string $displayName,
        public ?string $providerAccountId,
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonInterface $tokenExpiresAt,
        public bool $hasCalendar,
        public bool $hasSend,
    ) {}
}
