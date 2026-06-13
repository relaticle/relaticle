<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;

mutates(ProposalCard::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

/**
 * @param  array<string, mixed>  $action
 * @param  array<string, mixed>  $display
 */
function proposalCardPa(User $user, array $action, array $display): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Company\\CreateCompany',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => $action,
        'display_data' => $display,
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('renders nothing when no active proposal id is set', function (): void {
    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->assertSet('pendingActionId', null)
        ->assertDontSee('Acme Corp');
});

it('loads and renders the active pending action summary', function (): void {
    $action = proposalCardPa($this->user,
        ['name' => 'Acme Corp'],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [['label' => 'Name', 'value' => 'Acme Corp']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', ['id' => $action->getKey(), 'context' => 'conversation'])
        ->assertSet('pendingActionId', $action->getKey())
        ->assertSee('Create company "Acme Corp"')
        ->assertSee('Acme Corp');
});

it('refuses a pending action from another tenant', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = proposalCardPa($other, ['name' => 'Foreign'], ['title' => 'x', 'summary' => 'x', 'fields' => []]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', ['id' => $foreign->getKey(), 'context' => 'conversation'])
        ->assertSet('pendingActionId', null);
});

it('ignores set-active events targeted at a different chat context', function (): void {
    $action = proposalCardPa($this->user, ['name' => 'Acme'], ['title' => 't', 'summary' => 's', 'fields' => []]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->call('setActive', ['id' => $action->getKey(), 'context' => 'side-panel'])
        ->assertSet('pendingActionId', null);
});
