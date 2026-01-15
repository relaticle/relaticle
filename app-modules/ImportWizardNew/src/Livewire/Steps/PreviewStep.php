<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Store\ImportStore;

/**
 * Step 4: Preview and import.
 *
 * Shows a summary of creates/updates before committing the import.
 * Navigation uses $parent.goBack() directly. Final step has no "next".
 */
final class PreviewStep extends Component
{
    #[Locked]
    public string $storeId;

    #[Locked]
    public ImportEntityType $entityType;

    protected ?ImportStore $store = null;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->storeId = $storeId;
        $this->entityType = $entityType;
        $this->store = ImportStore::load($storeId);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.preview-step', [
            'headers' => $this->store?->headers() ?? [],
            'rowCount' => $this->store?->rowCount() ?? 0,
        ]);
    }
}
