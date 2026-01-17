<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Component;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Livewire\Concerns\WithImportStore;

/**
 * Step 2: Column mapping.
 *
 * Maps CSV columns to entity fields with auto-detection and manual adjustment.
 * Navigation uses $parent.nextStep() and $parent.goBack() directly.
 */
final class MappingStep extends Component
{
    use WithImportStore;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.mapping-step', [
            'headers' => $this->headers(),
            'rowCount' => $this->rowCount(),
        ]);
    }
}
