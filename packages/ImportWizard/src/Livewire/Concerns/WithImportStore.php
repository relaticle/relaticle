<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Concerns;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
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
        $this->import ??= Import::query()
            ->forWorkspace($this->getCurrentWorkspaceId() ?? '')
            ->findOrFail($this->storeId);

        return $this->import;
    }

    protected function refreshImport(): Import
    {
        $this->import = null;

        return $this->import();
    }

    protected function store(): ImportStore
    {
        $store = $this->store ??= ImportStore::forRead($this->storeId);

        abort_if(! $store instanceof ImportStore, 404, __('Import session not found or expired.'));

        return $store;
    }

    /** @param  Closure(ImportStore): mixed  $mutator */
    protected function writeStore(Closure $mutator): bool
    {
        $this->store?->close();
        $this->store = null;

        try {
            ImportStore::withWriteLock($this->storeId, $mutator, (int) config('import-wizard.store.lock.wait.web'));
        } catch (ImportStoreException $e) {
            abort_if($e->isNotFound(), 404, __('Import session not found or expired.'));

            throw_unless($e->isLockTimeout(), $e);

            Notification::make()->title(__('import-wizard-new::store.busy'))->warning()->send();

            return false;
        }

        return true;
    }

    private function getCurrentWorkspaceId(): ?string
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
