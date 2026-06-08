<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\MatchResolver;

#[Timeout(120)]
#[Tries(1)]
final class ResolveMatchesJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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
        $store = ImportStore::load($this->importId);

        if (! $store instanceof ImportStore) {
            return;
        }

        $importer = $import->getImporter();

        new MatchResolver($store, $import, $importer)->resolve();
    }
}
