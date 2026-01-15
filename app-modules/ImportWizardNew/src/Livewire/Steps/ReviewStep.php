<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Store\ImportStore;

/**
 * Step 3: Value review.
 *
 * Reviews unique values and allows fixing invalid data.
 * Navigation uses $parent.nextStep() and $parent.goBack() directly.
 */
final class ReviewStep extends Component
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
        return view('import-wizard-new::livewire.steps.review-step', [
            'headers' => $this->store?->headers() ?? [],
            'rowCount' => $this->store?->rowCount() ?? 0,
        ]);
    }
}
