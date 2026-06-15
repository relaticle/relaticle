<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\Flowforge\Board;

mutates(OpportunitiesBoard::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);

    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->stageField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->first();
});

function getOpportunityBoard(): Board
{
    $component = livewire(OpportunitiesBoard::class);

    return $component->instance()->getBoard();
}

it('can render the board page', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertOk();
});

it('displays opportunities in the correct board columns', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $closedWon = $this->stageField->options->firstWhere('name', 'Closed Won');

    $prospectingOpportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
    $prospectingOpportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    $closedWonOpportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
    $closedWonOpportunity->saveCustomFieldValue($this->stageField, $closedWon->getKey());

    $board = getOpportunityBoard();

    expect($board->getBoardRecords((string) $prospecting->getKey())->pluck('id'))
        ->toContain($prospectingOpportunity->id)
        ->and($board->getBoardRecords((string) $closedWon->getKey())->pluck('id'))
        ->toContain($closedWonOpportunity->id);
});

it('does not show opportunities from other teams', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherOpportunity = Opportunity::factory()->for($otherUser->currentTeam)->create();

    $board = getOpportunityBoard();
    $allRecordIds = collect($this->stageField->options)
        ->flatMap(fn ($opt) => $board->getBoardRecords((string) $opt->getKey()))
        ->pluck('id');

    expect($allRecordIds)->not->toContain($otherOpportunity->id);
});

it('shows the view switcher linking list and board views', function (): void {
    livewire(ListOpportunities::class)
        ->assertSeeHtml(OpportunityResource::getUrl('board'));

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml(OpportunityResource::getUrl('index'));
});

it('redirects the legacy board url to the resource board page', function (): void {
    $this->get(route('filament.app.opportunities-board.redirect', ['tenant' => $this->team->slug]))
        ->assertRedirect(OpportunityResource::getUrl('board'));
});

it('moves a card between columns via moveCard', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, (string) $qualification->getKey())
        ->assertDispatched('kanban-card-moved');

    $updatedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($updatedValue)->toBe($qualification->getKey());
});
