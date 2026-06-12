<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Team;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AiCreditBalanceResource::class);
mutates(CreditService::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('resets the period using the chosen plan allowance and logs an audit transaction', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    $balance = AiCreditBalance::factory()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 12,
        'credits_used' => 488,
    ]);

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('resetPeriod')->table($balance), [
            'plan' => 'pro',
        ])
        ->assertHasNoActionErrors();

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(Plan::Pro->credits())
        ->and($balance->credits_used)->toBe(0);

    expect($team->fresh()->plan)->toBe(Plan::Pro);

    $transaction = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('type', AiCreditType::Adjustment)
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->credits_charged)->toBe(0)
        ->and($transaction->metadata['action'])->toBe('reset_period')
        ->and($transaction->metadata['plan'])->toBe('pro')
        ->and($transaction->metadata['allowance_granted'])->toBe(Plan::Pro->credits())
        ->and($transaction->metadata['previous_credits_remaining'])->toBe(12)
        ->and($transaction->metadata['previous_credits_used'])->toBe(488)
        ->and($transaction->metadata['sysadmin_id'])->toBe((string) $this->admin->getKey());
});

it('resets multiple balances via the bulk action', function (): void {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team1->getKey())->delete();
    $b1 = AiCreditBalance::factory()->create(['team_id' => $team1->getKey(), 'credits_remaining' => 0, 'credits_used' => 100]);
    AiCreditBalance::query()->where('team_id', $team2->getKey())->delete();
    $b2 = AiCreditBalance::factory()->create(['team_id' => $team2->getKey(), 'credits_remaining' => 5, 'credits_used' => 200]);

    livewire(ListAiCreditBalances::class)
        ->selectTableRecords([$b1->getKey(), $b2->getKey()])
        ->callAction(
            [['name' => 'resetPeriod', 'context' => ['table' => true, 'bulk' => true]]],
            data: ['plan' => 'enterprise'],
        )
        ->assertHasNoActionErrors();

    expect($b1->refresh()->credits_remaining)->toBe(Plan::Enterprise->credits())
        ->and($b2->refresh()->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('renders all Plan enum cases as reset-action plan options', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    $balance = AiCreditBalance::factory()->create(['team_id' => $team->getKey()]);

    livewire(ListAiCreditBalances::class)
        ->mountAction(TestAction::make('resetPeriod')->table($balance))
        ->assertMountedActionModalSee('Free')
        ->assertMountedActionModalSee('Pro')
        ->assertMountedActionModalSee('Enterprise');
});
