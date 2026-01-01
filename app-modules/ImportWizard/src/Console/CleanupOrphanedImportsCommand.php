<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

final class CleanupOrphanedImportsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'import:cleanup {--hours=24 : Delete sessions older than this many hours}';

    /**
     * @var string
     */
    protected $description = 'Clean up orphaned import session files';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);
        $deleted = 0;

        $directories = Storage::disk('local')->directories('temp-imports');

        foreach ($directories as $dir) {
            $originalFile = "{$dir}/original.csv";

            if (! Storage::disk('local')->exists($originalFile)) {
                continue;
            }

            $lastModified = Storage::disk('local')->lastModified($originalFile);

            if (Carbon::createFromTimestamp($lastModified)->lt($cutoff)) {
                $sessionId = basename($dir);

                Storage::disk('local')->deleteDirectory($dir);
                Cache::forget("import:{$sessionId}:status");
                Cache::forget("import:{$sessionId}:progress");
                Cache::forget("import:{$sessionId}:team");

                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} orphaned import sessions.");

        return Command::SUCCESS;
    }
}
