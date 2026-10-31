<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;

final readonly class PickAgentSendTime
{
    private const int MIN_HOLD_SECONDS = 60;

    public function execute(?DateTimeInterface $requested = null): CarbonImmutable
    {
        $holdEnds = now()->addSeconds(max(self::MIN_HOLD_SECONDS, Config::integer('email-integration.outbox.agent_send_hold_seconds')));

        return $requested instanceof DateTimeInterface && $holdEnds->isBefore($requested)
            ? CarbonImmutable::instance($requested)->utc()
            : $holdEnds;
    }
}
