<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use Carbon\CarbonInterface;
use Relaticle\EmailIntegration\Actions\ReserveAgentHold;
use RuntimeException;

final class AgentOutboxFull extends RuntimeException
{
    public static function atLimit(int $limit, CarbonInterface $nextSendsAt): self
    {
        $reason = self::reason($limit, $nextSendsAt);

        return new self("Not sent. {$reason}");
    }

    public static function forMove(int $limit, CarbonInterface $nextSendsAt, ?CarbonInterface $staysAt): self
    {
        $reason = self::reason($limit, $nextSendsAt);

        if (! $staysAt instanceof CarbonInterface) {
            return new self("Not moved. {$reason}");
        }

        $stays = self::time($staysAt);

        return new self("Not moved. It still leaves at {$stays}. {$reason}");
    }

    private static function reason(int $limit, CarbonInterface $nextSendsAt): string
    {
        $hours = ReserveAgentHold::WINDOW_HOURS;
        $next = self::time($nextSendsAt);

        return "This user already has {$limit} assistant emails due to go out in the next {$hours} hours, which is the most allowed at once. The next one leaves at {$next}, and a slot opens then. Try again after that time, or ask the user to cancel a waiting email in Relaticle.";
    }

    private static function time(CarbonInterface $moment): string
    {
        $utc = $moment->toImmutable()->utc();

        return "{$utc->format('H:i')} UTC ({$utc->toIso8601ZuluString()})";
    }
}
