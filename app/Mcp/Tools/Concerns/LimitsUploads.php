<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Team;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Response;

trait LimitsUploads
{
    private const int UPLOADS_PER_HOUR = 60;

    protected function denyIfUploadLimitReached(Team $team): ?Response
    {
        $key = "mcp-uploads:{$team->getKey()}";

        if (RateLimiter::tooManyAttempts($key, self::UPLOADS_PER_HOUR)) {
            return Response::error(__('uploads.errors.rate_limited'));
        }

        RateLimiter::hit($key, 3600);

        return null;
    }
}
