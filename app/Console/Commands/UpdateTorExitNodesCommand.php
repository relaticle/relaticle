<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\SignupChallenge;
use App\Support\Http\TorExitNodes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use UnexpectedValueException;

#[Description('Refresh the Tor exit addresses that sign-up refuses')]
#[Signature('tor:update-exit-nodes')]
final class UpdateTorExitNodesCommand extends Command
{
    public function handle(TorExitNodes $exitNodes): int
    {
        if (! Feature::active(SignupChallenge::class)) {
            $this->comment('Skipped: the sign-up challenge feature is off.');

            return self::SUCCESS;
        }

        $this->info('Fetching the Tor exit list...');

        try {
            $count = $exitNodes->refresh();
        } catch (HttpClientException|UnexpectedValueException $exception) {
            Log::warning('The Tor exit list could not be refreshed; the previous list stays in use.', [
                'exception' => $exception->getMessage(),
            ]);

            $this->error("The Tor exit list could not be refreshed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->comment("Stored {$count} Tor exit addresses.");

        return self::SUCCESS;
    }
}
