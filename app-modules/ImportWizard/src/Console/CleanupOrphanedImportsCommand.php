<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Relaticle\ImportWizard\Data\ImportSessionData;

/**
 * Cleans up orphaned import session files from temp-imports/ directory.
 *
 * CLEANUP SAFETY: Uses dual-check system to prevent accidental deletion:
 *
 * 1. FILE AGE CHECK (--hours, default 2 hours)
 *    Protects against: network interruptions, user taking a break
 *    Even if heartbeat stops, we wait before deleting
 *
 * 2. HEARTBEAT CHECK (5 minutes stale)
 *    Protects against: deleting while user is actively working
 *    If heartbeat is recent, user is present - don't delete
 *
 * Only deletes when BOTH conditions are true:
 * - File is older than threshold (user had plenty of time)
 * - Heartbeat is stale (user definitely left)
 *
 * Note: Successfully completed imports clean up automatically via cleanupTempFile().
 * This command only handles abandoned/orphaned sessions.
 */
final class CleanupOrphanedImportsCommand extends Command
{
    protected $signature = 'import:cleanup
        {--hours=2 : Minimum file age in hours before considering for deletion}
        {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clean up orphaned import session files from temp-imports/';

    /**
     * Heartbeat stale threshold: 5 minutes.
     *
     * Why 5 minutes (not shorter)?
     * - Allows for brief network interruptions
     * - Allows for user thinking/reading time
     * - Browser tabs may pause JS when inactive
     *
     * Why 5 minutes (not longer)?
     * - Quick detection of truly abandoned sessions
     * - Combined with file age check provides safety
     */
    private const int HEARTBEAT_STALE_SECONDS = 300;

    public function handle(): int
    {
        $minAgeHours = (int) $this->option('hours');
        $isDryRun = (bool) $this->option('dry-run');
        $ageCutoff = now()->subHours($minAgeHours);

        $directories = Storage::disk('local')->directories('temp-imports');

        if ($directories === []) {
            $this->info('No import sessions found.');

            return Command::SUCCESS;
        }

        $this->info('Scanning '.count($directories).' import sessions...');

        if ($isDryRun) {
            $this->warn('DRY RUN: No files will be deleted.');
        }

        $deleted = 0;
        $skipped = 0;

        foreach ($directories as $directory) {
            $result = $this->processSession($directory, $ageCutoff, $isDryRun);

            if ($result) {
                $deleted++;
            } else {
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Deleted: {$deleted} | Skipped: {$skipped}");

        return Command::SUCCESS;
    }

    private function processSession(string $directory, Carbon $ageCutoff, bool $isDryRun): bool
    {
        $sessionId = basename($directory);
        $csvFile = "{$directory}/original.csv";

        if (! Storage::disk('local')->exists($csvFile)) {
            $this->lineVerbose("  [{$sessionId}] No CSV file, skipping");

            return false;
        }

        $fileAge = Carbon::createFromTimestamp(
            Storage::disk('local')->lastModified($csvFile)
        );
        $isOldEnough = $fileAge->lt($ageCutoff);

        $sessionData = ImportSessionData::find($sessionId);
        $isAbandoned = ! $sessionData instanceof ImportSessionData
            || $sessionData->isHeartbeatStale(self::HEARTBEAT_STALE_SECONDS);

        if (! $isOldEnough) {
            $this->lineVerbose("  [{$sessionId}] Too recent ({$fileAge->diffForHumans()})");

            return false;
        }

        if (! $isAbandoned) {
            $this->lineVerbose("  [{$sessionId}] Heartbeat active, user present");

            return false;
        }

        if ($isDryRun) {
            $this->line("  Would delete: {$sessionId} (age: {$fileAge->diffForHumans()})");

            return true;
        }

        Storage::disk('local')->deleteDirectory($directory);
        ImportSessionData::forget($sessionId);

        $this->line("  Deleted: {$sessionId} (age: {$fileAge->diffForHumans()})");

        return true;
    }

    private function lineVerbose(string $message): void
    {
        if ($this->output->isVerbose()) {
            $this->line($message);
        }
    }
}
