<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Exception;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Exceptions\ImportFileException;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\ImportFileLoader;

final class UploadStep extends Component implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    #[Locked]
    public ImportEntityType $entityType;

    #[Locked]
    public ?string $storeId = null;

    #[Validate('required|file|max:10240|mimes:csv,txt')]
    public ?TemporaryUploadedFile $uploadedFile = null;

    /** @var list<string> */
    public array $headers = [];

    public int $rowCount = 0;

    public bool $isParsed = false;

    private ?Import $import = null;

    private ?ImportStore $store = null;

    public function mount(ImportEntityType $entityType, ?string $storeId = null): void
    {
        $this->entityType = $entityType;
        $this->storeId = $storeId;

        if ($storeId === null) {
            return;
        }

        $this->import = Import::query()
            ->forWorkspace($this->getCurrentWorkspaceId() ?? '')
            ->find($storeId);

        if (! $this->import instanceof Import) {
            return;
        }

        $this->headers = $this->import->headers ?? [];
        $this->rowCount = $this->import->total_rows;
        $this->isParsed = true;
    }

    private function getCurrentWorkspaceId(): ?string
    {
        $tenant = filament()->getTenant();

        return $tenant instanceof Model ? (string) $tenant->getKey() : null;
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.upload-step');
    }

    public function updatedUploadedFile(): void
    {
        $this->resetErrorBag('uploadedFile');
        $this->validateFile();
    }

    private function validateFile(): void
    {
        if (! $this->uploadedFile instanceof TemporaryUploadedFile) {
            return;
        }

        $this->cleanupExisting();
        $this->reset(['headers', 'rowCount', 'isParsed']);

        try {
            ['headers' => $this->headers, 'row_count' => $this->rowCount] = resolve(ImportFileLoader::class)
                ->inspect($this->uploadedFile->getRealPath());
            $this->isParsed = true;
        } catch (ImportFileException $e) {
            $this->addError('uploadedFile', $e->getMessage());
        } catch (Exception $e) {
            report($e);
            $this->addError('uploadedFile', 'Unable to process this file. Please check the format and try again.');
        }
    }

    public function continueToMapping(): void
    {
        if (! $this->isParsed || ! $this->uploadedFile instanceof TemporaryUploadedFile) {
            $this->addError('uploadedFile', 'File no longer available. Please re-upload.');
            $this->reset(['headers', 'rowCount', 'isParsed']);

            return;
        }

        $workspaceId = $this->getCurrentWorkspaceId();

        if (blank($workspaceId)) {
            $this->addError('uploadedFile', 'Unable to determine your workspace. Please refresh and try again.');

            return;
        }

        try {
            $this->import = resolve(ImportFileLoader::class)->load(
                $this->uploadedFile->getRealPath(),
                $this->uploadedFile->getClientOriginalName(),
                $this->entityType,
                $workspaceId,
                (string) auth()->id(),
            );
            $this->store = ImportStore::load($this->import->id);

            $this->dispatch('completed', storeId: $this->import->id, rowCount: $this->import->total_rows, columnCount: count($this->headers));
        } catch (ImportFileException $e) {
            $this->addError('uploadedFile', $e->getMessage());
            $this->reset(['headers', 'rowCount', 'isParsed']);
        } catch (Exception $e) {
            report($e);
            $this->addError('uploadedFile', 'Unable to process this file. Please try again or use a different file.');
        }
    }

    public function removeFile(): void
    {
        $this->cleanupExisting();
        $this->reset(['storeId', 'uploadedFile', 'headers', 'rowCount', 'isParsed']);
    }

    private function cleanupExisting(): void
    {
        if ($this->store instanceof ImportStore) {
            $this->store->destroy();
            $this->store = null;
        }

        if ($this->import instanceof Import) {
            $this->import->delete();
            $this->import = null;
        }
    }
}
