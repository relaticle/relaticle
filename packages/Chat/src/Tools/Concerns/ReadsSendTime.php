<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Date;

trait ReadsSendTime
{
    protected function sendTime(mixed $value, User $user): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Date::parse($value, $user->effectiveTimezone())->utc()->toIso8601String();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    protected function sendTimeError(mixed $value, User $user): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $sendAt = $this->sendTime($value, $user);

        return match (true) {
            $sendAt === null => 'scheduled_for is not a date and time. Use ISO 8601, such as 2026-11-03T09:00.',
            ! Date::parse($sendAt)->isFuture() => 'scheduled_for has already passed. Pick a later time.',
            default => null,
        };
    }

    protected function sendTimeLabel(string $sendAt, User $user): string
    {
        return Date::parse($sendAt)->setTimezone($user->effectiveTimezone())->isoFormat('LLL');
    }

    protected function sendTimeDescription(): string
    {
        return 'Date and time to send at, as ISO 8601. Read in the user\'s timezone unless it carries an offset.';
    }
}
