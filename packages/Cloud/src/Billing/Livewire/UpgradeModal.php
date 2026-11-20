<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Livewire;

use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Relaticle\Cloud\Billing\Livewire\Concerns\StartsSubscriptionCheckout;

final class UpgradeModal extends Component
{
    use StartsSubscriptionCheckout;

    public const string MODAL_ID = 'upgrade-plan';

    public function render(): View
    {
        $workspace = Filament::getTenant();

        return view('cloud::livewire.upgrade-modal', ['workspace' => $workspace instanceof Workspace ? $workspace : null]);
    }
}
