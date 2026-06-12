<?php

declare(strict_types=1);

use App\Models\Team;
use Filament\Facades\Filament;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ViewAiCreditBalance;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AiCreditBalanceResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('lists balances across all tenants', function (): void {
    $team1 = Team::factory()->create(['name' => 'Acme']);
    $team2 = Team::factory()->create(['name' => 'Globex']);
    AiCreditBalance::query()->where('team_id', $team1->getKey())->delete();
    $b1 = AiCreditBalance::factory()->create(['team_id' => $team1->getKey(), 'credits_remaining' => 200]);
    AiCreditBalance::query()->where('team_id', $team2->getKey())->delete();
    $b2 = AiCreditBalance::factory()->create(['team_id' => $team2->getKey(), 'credits_remaining' => 50]);

    livewire(ListAiCreditBalances::class)
        ->assertCanSeeTableRecords([$b1, $b2])
        ->assertCanRenderTableColumn('team.name')
        ->assertCanRenderTableColumn('credits_remaining')
        ->assertCanRenderTableColumn('credits_used');
});

it('filters by low balance', function (): void {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team1->getKey())->delete();
    $low = AiCreditBalance::factory()->create(['team_id' => $team1->getKey(), 'credits_remaining' => 5]);
    AiCreditBalance::query()->where('team_id', $team2->getKey())->delete();
    $high = AiCreditBalance::factory()->create(['team_id' => $team2->getKey(), 'credits_remaining' => 500]);

    livewire(ListAiCreditBalances::class)
        ->filterTable('low_balance')
        ->assertCanSeeTableRecords([$low])
        ->assertCanNotSeeTableRecords([$high]);
});

it('filters by expired period', function (): void {
    $team1 = Team::factory()->create();
    $team2 = Team::factory()->create();
    AiCreditBalance::query()->where('team_id', $team1->getKey())->delete();
    $expired = AiCreditBalance::factory()->create([
        'team_id' => $team1->getKey(),
        'period_starts_at' => now()->subMonth(),
        'period_ends_at' => now()->subDay(),
    ]);
    AiCreditBalance::query()->where('team_id', $team2->getKey())->delete();
    $current = AiCreditBalance::factory()->create([
        'team_id' => $team2->getKey(),
        'period_ends_at' => now()->addDays(10),
    ]);

    livewire(ListAiCreditBalances::class)
        ->filterTable('period_expired')
        ->assertCanSeeTableRecords([$expired])
        ->assertCanNotSeeTableRecords([$current]);
});

it('shows the balance detail page', function (): void {
    $team = Team::factory()->create();
    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    livewire(ViewAiCreditBalance::class, ['record' => $balance->getKey()])
        ->assertSuccessful();
});
