<?php

declare(strict_types=1);

use App\Models\Team;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AiCreditBalanceResource::class);

beforeEach(function (): void {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('grants credits via the adjust action and logs an Adjustment transaction', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    $balance = AiCreditBalance::factory()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
    ]);

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('adjust')->table($balance), [
            'delta' => 50,
            'reason' => 'Compensation for outage',
        ])
        ->assertHasNoActionErrors();

    expect($balance->refresh()->credits_remaining)->toBe(150);

    $transaction = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('type', AiCreditType::Adjustment)
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->credits_charged)->toBe(50)
        ->and($transaction->metadata['delta'])->toBe(50)
        ->and($transaction->metadata['reason'])->toBe('Compensation for outage')
        ->and($transaction->metadata['sysadmin_id'])->toBe((string) $this->admin->getKey());
});

it('revokes credits when delta is negative and leaves the spend meter untouched', function (): void {
    $team = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();
    AiCreditTransaction::query()->where('team_id', $team->getKey())->delete();
    $balance = AiCreditBalance::factory()->create([
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 20,
    ]);

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('adjust')->table($balance), [
            'delta' => -30,
            'reason' => 'Manual chargeback',
        ])
        ->assertHasNoActionErrors();

    $balance->refresh();
    expect($balance->credits_remaining)->toBe(70)
        ->and($balance->credits_used)->toBe(20);
});

it('rejects an adjust action without a reason', function (): void {
    $team = Team::factory()->create();
    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    livewire(ListAiCreditBalances::class)
        ->callAction(TestAction::make('adjust')->table($balance), [
            'delta' => 10,
            'reason' => '',
        ])
        ->assertHasActionErrors(['reason']);
});
