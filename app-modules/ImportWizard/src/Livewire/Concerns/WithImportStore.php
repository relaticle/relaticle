<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Livewire\Attributes\Locked;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Store\ImportStore;

/**
 * Shared functionality for wizard step components that need ImportStore access.
 */
trait WithImportStore
{
    #[Locked]
    public string $storeId;

    #[Locked]
    public ImportEntityType $entityType;

    private ?ImportStore $store = null;

    public function mountWithImportStore(string $storeId, ImportEntityType $entityType): void
    {
        $this->storeId = $storeId;
        $this->entityType = $entityType;
        $this->store = ImportStore::load($storeId, $this->getCurrentTeamId());
    }

    protected function store(): ?ImportStore
    {
        return $this->store ??= ImportStore::load($this->storeId, $this->getCurrentTeamId());
    }

    private function getCurrentTeamId(): ?string
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof \Illuminate\Database\Eloquent\Model ? (string) $tenant->getKey() : null;
    }

    /**
     * @return list<string>
     *
     * @throws FileNotFoundException
     */
    protected function headers(): array
    {
        return $this->store()?->headers() ?? [];
    }

    protected function rowCount(): int
    {
        return $this->store()?->rowCount() ?? 0;
    }
}
