<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use Carbon\CarbonImmutable;
use Relaticle\EmailIntegration\Actions\ReserveAgentHold;
use RuntimeException;

final class AgentOutboxFull extends RuntimeException
{
    public static function atLimit(int $limit, CarbonImmutable $nextSendsAt): self
    {
        $hours = ReserveAgentHold::WINDOW_HOURS;
        $utc = $nextSendsAt->utc();

        return new self("Not sent. This user already has {$limit} assistant emails due to go out in the next {$hours} hours, which is the most allowed at once. The next one leaves at {$utc->format('H:i')} UTC ({$utc->toIso8601ZuluString()}), and a slot opens then. Try again after that time, or ask the user to cancel a waiting email in Relaticle.");
    }
}
