<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use App\Support\CurrentWorkspace;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\MatchResolver;

#[Timeout(120)]
#[Tries(1)]
final class ResolveMatchesJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        private readonly string $importId,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $import = Import::query()->findOrFail($this->importId);

        try {
            resolve(CurrentWorkspace::class)->within($import->workspace_id, fn () => $this->resolveMatches($import));
        } catch (ImportStoreException $e) {
            throw_unless($e->isNotFound(), $e);
        }
    }

    private function resolveMatches(Import $import): void
    {
        $importer = $import->getImporter();

        ImportStore::withWriteLock($this->importId, function (ImportStore $store) use ($import, $importer): void {
            if ($this->batch()?->cancelled()) {
                return;
            }

            new MatchResolver($store, $import, $importer)->resolve();
        });
    }
}
