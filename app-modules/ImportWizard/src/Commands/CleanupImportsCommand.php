<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Relaticle\ImportWizard\Enums\ImportStatus;

final class CleanupImportsCommand extends Command
{
    protected $signature = 'import:cleanup
        {--hours=24 : Delete imports older than this many hours}
        {--completed-hours=2 : Delete completed/failed imports older than this many hours}';

    protected $description = 'Clean up stale and completed import files';

    public function handle(): void
    {
        $importsPath = storage_path('app/imports');

        if (! File::isDirectory($importsPath)) {
            $this->comment('No imports directory found.');

            return;
        }

        $staleHours = (int) $this->option('hours');
        $completedHours = (int) $this->option('completed-hours');
        $deleted = 0;

        foreach (File::directories($importsPath) as $directory) {
            $metaPath = $directory.'/meta.json';

            if (! File::exists($metaPath)) {
                File::deleteDirectory($directory);
                $deleted++;

                continue;
            }

            $meta = json_decode(File::get($metaPath), true);

            if (! is_array($meta) || ! isset($meta['updated_at'])) {
                File::deleteDirectory($directory);
                $deleted++;

                continue;
            }

            $updatedAt = Carbon::parse($meta['updated_at']);
            $status = ImportStatus::tryFrom($meta['status'] ?? '');

            $isTerminal = in_array($status, [ImportStatus::Completed, ImportStatus::Failed], true);
            $maxAge = $isTerminal ? $completedHours : $staleHours;

            if ($updatedAt->diffInHours(now()) < $maxAge) {
                continue;
            }

            $this->info("Cleaning up import {$meta['id']} (status: {$meta['status']}, updated: {$updatedAt->diffForHumans()})");
            File::deleteDirectory($directory);
            $deleted++;
        }

        $this->comment("Cleaned up {$deleted} import(s).");
    }
}
