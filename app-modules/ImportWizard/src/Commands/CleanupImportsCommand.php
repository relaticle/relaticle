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

        $deleted += $this->cleanupTerminalImportFiles($completedHours);
        $deleted += $this->cleanupAbandonedImports($staleHours);
        $deleted += $this->cleanupOrphanedDirectories();

        $this->comment("Cleaned up {$deleted} import(s).");
    }

    private function cleanupTerminalImportFiles(int $completedHours): int
    {
        $deleted = 0;

        $terminalImports = Import::query()
            ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->where('updated_at', '<', now()->subHours($completedHours))
            ->get();

        foreach ($terminalImports as $import) {
            $store = ImportStore::load($import->id);

            if ($store === null) {
                continue;
            }

            $this->info("Cleaning up files for import {$import->id} (status: {$import->status->value})");
            $store->destroy();
            $deleted++;
        }

        return $deleted;
    }

    private function cleanupAbandonedImports(int $staleHours): int
    {
        $deleted = 0;

        $abandonedImports = Import::query()
            ->whereNotIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->where('updated_at', '<', now()->subHours($staleHours))
            ->get();

        foreach ($abandonedImports as $import) {
            $this->info("Cleaning up abandoned import {$import->id} (status: {$import->status->value})");
            ImportStore::load($import->id)?->destroy();
            $import->delete();
            $deleted++;
        }

        return $deleted;
    }

    private function cleanupOrphanedDirectories(): int
    {
        $deleted = 0;
        $importsPath = storage_path('app/imports');

        if (! File::isDirectory($importsPath)) {
            return 0;
        }

        foreach (File::directories($importsPath) as $directory) {
            $id = basename($directory);

            if (Import::where('id', $id)->exists()) {
                continue;
            }

            $this->info("Cleaning up orphaned directory {$id}");
            File::deleteDirectory($directory);
            $deleted++;
        }

        return $deleted;
    }
}
