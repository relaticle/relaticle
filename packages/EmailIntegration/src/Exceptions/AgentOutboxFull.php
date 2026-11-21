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
        return new self('Not sent. '.self::reason($limit, $nextSendsAt));
    }

    public static function forMove(int $limit, CarbonInterface $nextSendsAt, ?CarbonInterface $staysAt): self
    {
        $stays = $staysAt instanceof CarbonInterface ? ' It still leaves at '.self::time($staysAt).'.' : '';

        return new self("Not moved.{$stays} ".self::reason($limit, $nextSendsAt));
    }

    private static function reason(int $limit, CarbonInterface $nextSendsAt): string
    {
        $hours = ReserveAgentHold::WINDOW_HOURS;

        return "This user already has {$limit} assistant emails due to go out in the next {$hours} hours, which is the most allowed at once. The next one leaves at ".self::time($nextSendsAt).', and a slot opens then. Try again after that time, or ask the user to cancel a waiting email in Relaticle.';
    }

    private static function time(CarbonInterface $moment): string
    {
        $utc = $moment->toImmutable()->utc();

        return "{$utc->format('H:i')} UTC ({$utc->toIso8601ZuluString()})";
    }
}
