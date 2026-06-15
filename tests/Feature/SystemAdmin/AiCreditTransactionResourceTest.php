<?php

declare(strict_types=1);

use App\Models\Team;
use Filament\Facades\Filament;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Pages\ListAiCreditTransactions;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditTransactions\Pages\ViewAiCreditTransaction;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(AiCreditTransactionResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('lists transactions across all tenants', function (): void {
    $team1 = Team::factory()->create(['name' => 'Acme']);
    $team2 = Team::factory()->create(['name' => 'Globex']);
    $t1 = AiCreditTransaction::factory()->create(['team_id' => $team1->getKey()]);
    $t2 = AiCreditTransaction::factory()->create(['team_id' => $team2->getKey()]);

    livewire(ListAiCreditTransactions::class)
        ->assertCanSeeTableRecords([$t1, $t2])
        ->assertCanRenderTableColumn('team.name')
        ->assertCanRenderTableColumn('credits_charged')
        ->assertCanRenderTableColumn('type');
});

it('filters transactions by type', function (): void {
    $team = Team::factory()->create();
    $chat = AiCreditTransaction::factory()->create([
        'team_id' => $team->getKey(),
        'type' => AiCreditType::Chat,
    ]);
    $adjustment = AiCreditTransaction::factory()->adjustment()->create([
        'team_id' => $team->getKey(),
    ]);

    livewire(ListAiCreditTransactions::class)
        ->filterTable('type', AiCreditType::Adjustment->value)
        ->assertCanSeeTableRecords([$adjustment])
        ->assertCanNotSeeTableRecords([$chat]);
});

it('shows the transaction detail page with metadata', function (): void {
    $transaction = AiCreditTransaction::factory()->adjustment()->create();

    livewire(ViewAiCreditTransaction::class, ['record' => $transaction->getKey()])
        ->assertSuccessful();
});

it('renders a reservation-type transaction without crashing (regression: unhandled match)', function (): void {
    $reservation = AiCreditTransaction::factory()->create([
        'type' => AiCreditType::Reservation,
    ]);

    livewire(ListAiCreditTransactions::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$reservation])
        ->assertCanRenderTableColumn('type');

    livewire(ViewAiCreditTransaction::class, ['record' => $reservation->getKey()])
        ->assertSuccessful();
});
