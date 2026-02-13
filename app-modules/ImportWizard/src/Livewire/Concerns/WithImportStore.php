<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

trait WithImportStore
{
    #[Locked]
    public string $storeId;

    #[Locked]
    public ImportEntityType $entityType;

    private ?Import $import = null;

    private ?ImportStore $store = null;

    public function mountWithImportStore(string $storeId, ImportEntityType $entityType): void
    {
        $this->storeId = $storeId;
        $this->entityType = $entityType;
    }

    protected function import(): Import
    {
        if ($this->import === null) {
            $this->import = Import::query()
                ->forTeam($this->getCurrentTeamId() ?? '')
                ->findOrFail($this->storeId);
        }

        return $this->import;
    }

    protected function refreshImport(): Import
    {
        $this->import = null;

        return $this->import();
    }

    protected function store(): ImportStore
    {
        $store = $this->store ??= ImportStore::load($this->storeId);

        if ($store === null) {
            abort(404, 'Import session not found or expired.');
        }

        return $store;
    }

    private function getCurrentTeamId(): ?string
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof Model ? (string) $tenant->getKey() : null;
    }

    /** @return list<string> */
    protected function headers(): array
    {
        return $this->import()->headers ?? [];
    }

    protected function rowCount(): int
    {
        return $this->import()->total_rows;
    }
}
