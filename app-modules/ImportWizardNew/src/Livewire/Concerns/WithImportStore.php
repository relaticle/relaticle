<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Concerns;

use Livewire\Attributes\Locked;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Store\ImportStore;

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
        $this->store = ImportStore::load($storeId);
    }

    protected function store(): ?ImportStore
    {
        return $this->store ??= ImportStore::load($this->storeId);
    }

    /**
     * @return list<string>
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
