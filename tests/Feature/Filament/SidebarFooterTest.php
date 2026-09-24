<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Workspace\Members;
use App\Models\User;
use App\Policies\WorkspacePolicy;
use App\Services\Billing\SidebarBillingState;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;

mutates(WorkspacePolicy::class, SidebarBillingState::class);

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);

    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
});

function sidebarFooterHtml(string $html): string
{
    $start = strpos($html, 'fi-sidebar-footer-activation');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, 'action-modals', $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

test('the owner sees the members link and the upgrade modal button', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    $footer = sidebarFooterHtml($this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent());

    expect($footer)
        ->toContain(Members::getUrl())
        ->toContain(__('billing.sidebar.keep_pro'))
        ->toContain('open-modal');
});

test('an admin sees the members link but no billing controls', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    $footer = sidebarFooterHtml($this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent());

    expect($footer)
        ->toContain(Members::getUrl())
        ->not->toContain(Billing::getUrl())
        ->not->toContain('open-modal');
});

test('a member sees neither the members link nor billing controls', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    $footer = sidebarFooterHtml($this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent());

    expect($footer)
        ->not->toContain(Members::getUrl())
        ->not->toContain(Billing::getUrl())
        ->not->toContain('open-modal');
});
