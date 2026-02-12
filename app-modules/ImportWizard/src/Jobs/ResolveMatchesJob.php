<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\MatchResolver;

final class ResolveMatchesJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        private readonly string $importId,
        private readonly string $teamId,
    ) {}

    public function handle(): void
    {
        $import = Import::findOrFail($this->importId);
        $store = ImportStore::load($this->importId);

        if ($store === null) {
            return;
        }

        $importer = $import->getImporter();

        new MatchResolver($store, $import, $importer)->resolve();
    }
}
