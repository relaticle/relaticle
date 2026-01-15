<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Store\ImportStore;

/**
 * Step 2: Column mapping.
 *
 * Maps CSV columns to entity fields with auto-detection and manual adjustment.
 * Navigation uses $parent.nextStep() and $parent.goBack() directly.
 */
final class MappingStep extends Component
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
        return view('import-wizard-new::livewire.steps.mapping-step', [
            'headers' => $this->store?->headers() ?? [],
            'rowCount' => $this->store?->rowCount() ?? 0,
        ]);
    }
}
