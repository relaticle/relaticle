<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonInterval;
use Illuminate\Validation\ValidationException;

final class WorkspaceInvitationRateLimitedException extends ValidationException
{
    public int $retryAfterSeconds = 0;

    public static function afterSeconds(int $seconds): self
    {
        $exception = self::withMessages(['email' => self::retryMessage($seconds)])
            ->errorBag('addWorkspaceMember');

        $exception->retryAfterSeconds = $seconds;

        return $exception;
    }

    public static function retryMessage(int $seconds): string
    {
        return __('workspaces.notifications.invite_rate_limited.body', [
            'time' => CarbonInterval::seconds($seconds)->cascade()->forHumans(),
        ]);
    }
}
