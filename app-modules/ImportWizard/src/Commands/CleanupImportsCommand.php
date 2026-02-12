<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

final class CleanupImportsCommand extends Command
{
    protected $signature = 'import:cleanup
        {--hours=24 : Delete abandoned imports older than this many hours}
        {--completed-hours=2 : Delete completed/failed import files older than this many hours}';

    protected $description = 'Clean up stale and completed import files';

    public function handle(): void
    {
        $staleHours = (int) $this->option('hours');
        $completedHours = (int) $this->option('completed-hours');
        $deleted = 0;

        // Clean up completed/failed import files (keep DB records for history)
        $terminal = Import::query()
            ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->where('updated_at', '<', now()->subHours($completedHours))
            ->get();

        foreach ($terminal as $import) {
            $store = ImportStore::load($import->id);
            if ($store !== null) {
                $this->info("Cleaning up files for import {$import->id} (status: {$import->status->value})");
                $store->destroy();
                $deleted++;
            }
        }

        // Clean up abandoned imports (non-terminal, stale) — delete both DB record and files
        $abandoned = Import::query()
            ->whereNotIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->where('updated_at', '<', now()->subHours($staleHours))
            ->get();

        foreach ($abandoned as $import) {
            $this->info("Cleaning up abandoned import {$import->id} (status: {$import->status->value})");
            ImportStore::load($import->id)?->destroy();
            $import->delete();
            $deleted++;
        }

        // Clean up orphaned directories
        $importsPath = storage_path('app/imports');
        if (File::isDirectory($importsPath)) {
            foreach (File::directories($importsPath) as $directory) {
                $id = basename($directory);
                if (! Import::where('id', $id)->exists()) {
                    $this->info("Cleaning up orphaned directory {$id}");
                    File::deleteDirectory($directory);
                    $deleted++;
                }
            }
        }

        $this->comment("Cleaned up {$deleted} import(s).");
    }
}
