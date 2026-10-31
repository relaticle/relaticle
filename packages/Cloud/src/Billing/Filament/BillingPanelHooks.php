<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Filament;

use App\Enums\WorkspaceCapability;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\SidebarBillingState;

final readonly class BillingPanelHooks
{
    public function __construct(private SidebarBillingState $sidebarState) {}

    public function upgradeModal(): string
    {
        if (! Feature::active(BillingFeature::class) || ! $this->billingManagedWorkspace() instanceof Workspace) {
            return '';
        }

        return Blade::render('@livewire(\Relaticle\Cloud\Billing\Livewire\UpgradeModal::class)');
    }

    public function sidebarBilling(): string
    {
        $workspace = $this->billingManagedWorkspace();
        $billing = $workspace instanceof Workspace ? $this->sidebarState->for($workspace) : null;

        if ($billing === null) {
            return '';
        }

        return view('cloud::filament.app.sidebar-billing', ['billing' => $billing, 'workspace' => $workspace])->render();
    }

    private function billingManagedWorkspace(): ?Workspace
    {
        $workspace = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User) {
            return null;
        }

        return $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage) ? $workspace : null;
    }
}
