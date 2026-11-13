<?php

declare(strict_types=1);

namespace App\Health;

use App\Features\SignupChallenge;
use App\Support\Http\TorExitNodes;
use Laravel\Pennant\Feature;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class TorExitListCheck extends Check
{
    // tor:update-exit-nodes runs hourly, so three missed runs in a row is an outage.
    private const int MAX_AGE_HOURS = 3;

    public function run(): Result
    {
        $result = Result::make();

        if (! Feature::active(SignupChallenge::class)) {
            return $result->ok()->shortSummary('off');
        }

        $refreshedAt = resolve(TorExitNodes::class)->refreshedAt();

        if ($refreshedAt === null) {
            return $result->failed('The Tor exit list has never been fetched, so sign-up accepts Tor exits.');
        }

        $result->shortSummary($refreshedAt->diffForHumans());

        if ($refreshedAt->isBefore(now()->subHours(self::MAX_AGE_HOURS))) {
            return $result->failed("The Tor exit list was last refreshed {$refreshedAt->diffForHumans()}.");
        }

        return $result->ok();
    }
}
