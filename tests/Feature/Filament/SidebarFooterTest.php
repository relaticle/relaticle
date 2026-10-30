<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Workspace\Members;
use App\Models\User;
use App\Policies\WorkspacePolicy;
use Filament\Facades\Filament;

mutates(WorkspacePolicy::class);

beforeEach(function (): void {
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

test('the owner sees the invite row and no billing controls when billing is off', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    $footer = sidebarFooterHtml($this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent());

    expect($footer)
        ->toContain('open-invite-workspace-members')
        ->not->toContain('/billing')
        ->not->toContain("id: 'upgrade-plan'");
});

test('an admin sees the invite row', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    $footer = sidebarFooterHtml($this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent());

    expect($footer)->toContain('open-invite-workspace-members');
});

test('a member sees neither the invite row nor billing controls', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    $footer = sidebarFooterHtml($this->get(Dashboard::getUrl(tenant: $this->workspace))->assertOk()->getContent());

    expect($footer)
        ->not->toContain('open-invite-workspace-members')
        ->not->toContain(Members::getUrl())
        ->not->toContain('/billing')
        ->not->toContain("id: 'upgrade-plan'");
});
