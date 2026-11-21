<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

final class AgentOutboxFull extends RuntimeException
{
    public static function atLimit(int $limit, int $windowHours, CarbonInterface $nextSendsAt, ?CarbonInterface $movingStaysAt = null): self
    {
        $opener = 'Not sent.';

        if ($movingStaysAt instanceof CarbonInterface) {
            $stays = self::time($movingStaysAt);
            $opener = "Not moved. It still leaves at {$stays}.";
        }

        $next = self::time($nextSendsAt);

        return new self("{$opener} This user already has {$limit} assistant emails due to go out in the next {$windowHours} hours, which is the most allowed at once. The next one leaves at {$next}, and a slot opens then. Try again after that time, or ask the user to cancel a waiting email in Relaticle.");
    }

    private static function time(CarbonInterface $moment): string
    {
        $utc = $moment->toImmutable()->utc();

        return "{$utc->format('H:i')} UTC ({$utc->toIso8601ZuluString()})";
    }
}
