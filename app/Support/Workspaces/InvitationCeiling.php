<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

use App\Exceptions\WorkspaceInvitationRateLimitedException;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final readonly class InvitationCeiling
{
    private const int WORKSPACE_PER_HOUR = 20;

    private const int WORKSPACE_PER_DAY = 50;

    private const int INVITER_PER_HOUR = 30;

    private const int HOUR_SECONDS = 3600;

    private const int DAY_SECONDS = 86400;

    public function ensureAvailable(User $inviter, Workspace $workspace): void
    {
        $retryAfter = 0;
        $tripped = [];

        foreach ($this->windows($inviter, $workspace) as $name => [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $tripped[] = $name;
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
        }

        if ($tripped === []) {
            return;
        }

        Log::warning('Workspace invitation limit reached', [
            'workspace_id' => $workspace->getKey(),
            'inviter_id' => $inviter->getKey(),
            'limits' => $tripped,
        ]);

        throw WorkspaceInvitationRateLimitedException::afterSeconds($retryAfter);
    }

    public function recordSent(User $inviter, Workspace $workspace): void
    {
        DB::afterCommit(function () use ($inviter, $workspace): void {
            foreach ($this->windows($inviter, $workspace) as [$key, , $seconds]) {
                RateLimiter::hit($key, $seconds);
            }
        });
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    private function windows(User $inviter, Workspace $workspace): array
    {
        return [
            'workspace_hour' => ["workspace-invitations:hour:{$workspace->getKey()}", self::WORKSPACE_PER_HOUR, self::HOUR_SECONDS],
            'workspace_day' => ["workspace-invitations:day:{$workspace->getKey()}", self::WORKSPACE_PER_DAY, self::DAY_SECONDS],
            'inviter_hour' => ["inviter-invitations:hour:{$inviter->getKey()}", self::INVITER_PER_HOUR, self::HOUR_SECONDS],
        ];
    }
}
