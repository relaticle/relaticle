<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Component;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;

/**
 * Step 4: Preview and import.
 *
 * Shows a summary of creates/updates before committing the import.
 * Navigation uses $parent.goBack() directly. Final step has no "next".
 */
final class PreviewStep extends Component
{
    use WithImportStore;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.preview-step', [
            'headers' => $this->headers(),
            'rowCount' => $this->rowCount(),
        ]);
    }
}
