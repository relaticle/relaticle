<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Cloud\Referral\Actions\EndReferralPrompt;
use Relaticle\Cloud\Referral\Enums\ReferralPromptOutcome;
use Relaticle\Cloud\Referral\Filament\ReferralPanelHooks;
use Relaticle\Cloud\Referral\Livewire\ReferralPromptCard;

mutates(ReferralPromptCard::class, ReferralPanelHooks::class, EndReferralPrompt::class, PendingAction::class);

beforeEach(function (): void {
    withHostedBilling();

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->workspace->forceFill(['trial_ends_at' => now()->addDays(9)])->save();
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

function proposalFor(User $user, Workspace $workspace, PendingActionStatus $status): PendingAction
{
    return PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'turn_id' => (string) Str::ulid(),
        'action_class' => 'App\\Actions\\Company\\CreateCompany',
        'operation' => PendingActionOperation::Create,
        'entity_type' => ProposalEntity::Company,
        'action_data' => ['name' => 'Acme'],
        'display_data' => ['title' => 'Create company'],
        'status' => $status,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => $status === PendingActionStatus::Pending ? null : now(),
    ]);
}

function dashboardHtml(Workspace $workspace): string
{
    return test()->get(Dashboard::getUrl(tenant: $workspace))->assertOk()->getContent();
}

it('stays out of the panel until the user has an approved proposal', function (PendingActionStatus $status): void {
    proposalFor($this->user, $this->workspace, $status);
    proposalFor(User::factory()->create(), $this->workspace, PendingActionStatus::Approved);

    expect(dashboardHtml($this->workspace))->not->toContain('data-referral-prompt');
})->with([PendingActionStatus::Pending, PendingActionStatus::Rejected, PendingActionStatus::Expired, PendingActionStatus::Superseded]);

it('shows once the user has an approved proposal', function (): void {
    proposalFor($this->user, $this->workspace, PendingActionStatus::Approved);

    expect(dashboardHtml($this->workspace))
        ->toContain('data-referral-prompt')
        ->toContain(__('cloud::referral.heading'));
});

it('ends for good when the link is copied', function (): void {
    proposalFor($this->user, $this->workspace, PendingActionStatus::Approved);

    livewire(ReferralPromptCard::class)
        ->call('copied')
        ->assertSee(__('cloud::referral.copied'));

    $this->assertDatabaseHas('referral_prompts', ['user_id' => $this->user->getKey(), 'outcome' => 'copied']);
    expect(dashboardHtml($this->workspace))->not->toContain('data-referral-prompt');
});

it('ends for good when dismissed', function (): void {
    proposalFor($this->user, $this->workspace, PendingActionStatus::Approved);

    livewire(ReferralPromptCard::class)
        ->call('dismiss')
        ->assertDontSee(__('cloud::referral.heading'));

    $this->assertDatabaseHas('referral_prompts', ['user_id' => $this->user->getKey(), 'outcome' => 'dismissed']);
    expect(dashboardHtml($this->workspace))->not->toContain('data-referral-prompt');
});

it('keeps the first outcome', function (): void {
    proposalFor($this->user, $this->workspace, PendingActionStatus::Approved);

    resolve(EndReferralPrompt::class)->execute($this->user, ReferralPromptOutcome::Copied);
    resolve(EndReferralPrompt::class)->execute($this->user, ReferralPromptOutcome::Dismissed);

    $this->assertDatabaseHas('referral_prompts', ['user_id' => $this->user->getKey(), 'outcome' => 'copied']);
    $this->assertDatabaseCount('referral_prompts', 1);
});

it('holds across workspaces', function (): void {
    $other = Workspace::factory()->create(['user_id' => $this->user->getKey(), 'trial_ends_at' => now()->addDays(9)]);
    $this->user->ownedWorkspaces()->save($other);
    proposalFor($this->user, $other, PendingActionStatus::Approved);

    expect(dashboardHtml($this->workspace))->toContain('data-referral-prompt');

    livewire(ReferralPromptCard::class)->call('dismiss');

    expect(dashboardHtml($other))->not->toContain('data-referral-prompt');
});

it('reaches a member of any role', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'viewer']);
    proposalFor($member, $this->workspace, PendingActionStatus::Approved);

    $this->actingAs($member);

    expect(dashboardHtml($this->workspace))->toContain('data-referral-prompt');
});

it('stays hidden when hosted billing is off', function (): void {
    proposalFor($this->user, $this->workspace, PendingActionStatus::Approved);
    Feature::define(BillingFeature::class, false);
    Feature::purge(BillingFeature::class);

    expect(dashboardHtml($this->workspace))->not->toContain('data-referral-prompt');
});

it('links the public site with the referral source', function (): void {
    config()->set('app.url', 'https://marketing.test');

    expect(livewire(ReferralPromptCard::class)->instance()->link())->toBe('https://marketing.test/?utm_source=referral');
});
