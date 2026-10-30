<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Filament;

use App\Enums\WorkspaceCapability;
use App\Features\Billing as BillingFeature;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Billing\SidebarBillingState;

final readonly class BillingPanelHooks
{
    public function __construct(private SidebarBillingState $sidebarState) {}

    public function upgradeModal(): string
    {
        $workspace = Filament::getTenant();
        $user = Auth::user();

        if (! Feature::active(BillingFeature::class) || ! $workspace instanceof Workspace || ! $user instanceof User || ! $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage)) {
            return '';
        }

        return Blade::render('@livewire(\Relaticle\Cloud\Billing\Livewire\UpgradeModal::class)');
    }

    public function sidebarBilling(): string
    {
        $workspace = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User || ! $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage)) {
            return '';
        }

        $billing = $this->sidebarState->for($workspace);

        if ($billing === null) {
            return '';
        }

        return view('cloud::filament.app.sidebar-billing', ['billing' => $billing, 'workspace' => $workspace])->render();
    }
}
