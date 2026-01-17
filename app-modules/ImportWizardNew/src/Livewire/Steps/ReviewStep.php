<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Livewire\Steps;

use Illuminate\View\View;
use Livewire\Component;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;
use Relaticle\ImportWizardNew\Livewire\Concerns\WithImportStore;

/**
 * Step 3: Value review.
 *
 * Reviews unique values and allows fixing invalid data.
 * Navigation uses $parent.nextStep() and $parent.goBack() directly.
 */
final class ReviewStep extends Component
{
    use WithImportStore;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.review-step', [
            'headers' => $this->headers(),
            'rowCount' => $this->rowCount(),
        ]);
    }
}
