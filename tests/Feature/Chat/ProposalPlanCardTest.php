<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ProposalCardFixture;

mutates(ProposalCard::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

describe('plan card', function (): void {
    it('presents every step of the turn, in order, with its dependency', function (): void {
        Bus::fake();
        [$company] = ProposalCardFixture::planSteps($this->user);

        $steps = Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->instance()
            ->stepViews();

        expect($steps)->toHaveCount(3)
            ->and(array_column($steps, 'position'))->toBe([1, 2, 3])
            ->and(array_column($steps, 'entity_type'))->toBe(['company', 'people', 'task'])
            ->and(array_column($steps, 'recordLabel'))->toBe(['Northwind Traders', 'Priya Raman', 'Call Priya'])
            ->and($steps[0]['blockedBy'])->toBe([])
            ->and($steps[1]['blockedBy'])->toBe([1])
            ->and($steps[2]['blockedBy'])->toBe([2])
            ->and($steps[1]['isActive'])->toBeFalse()
            ->and($steps[0]['isActive'])->toBeTrue();
    });

    it('creates every record from one approval', function (): void {
        Bus::fake();
        [$company] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('approveAll')
            ->assertSet('pendingActionId', null)
            ->assertHasNoErrors();

        $created = Company::query()->where('name', 'Northwind Traders')->firstOrFail();

        expect(People::query()->where('name', 'Priya Raman')->value('company_id'))->toBe((string) $created->getKey())
            ->and(Task::query()->where('title', 'Call Priya')->exists())->toBeTrue();
    });

    it('refuses a step whose dependency is still pending', function (): void {
        Bus::fake();
        [$company, $person] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('approveStep', $person->getKey())
            ->assertHasErrors('resolve');

        expect(People::query()->count())->toBe(0)
            ->and($person->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('approves one step and keeps the rest waiting', function (): void {
        Bus::fake();
        [$company, $person, $task] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('approveStep', $company->getKey())
            ->assertHasNoErrors();

        expect(Company::query()->where('name', 'Northwind Traders')->exists())->toBeTrue()
            ->and($person->refresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('cancels the dependent steps when one is rejected', function (): void {
        Bus::fake();
        [$company, $person, $task] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('rejectStep', $person->getKey())
            ->assertHasNoErrors();

        expect($person->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($company->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('discards the whole plan without writing anything', function (): void {
        Bus::fake();
        [$company, $person, $task] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('discardAll')
            ->assertSet('pendingActionId', null);

        expect($company->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($person->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and(Company::query()->count())->toBe(0);
    });
});

it('refuses a client-set editing target, so one payload cannot open the same field on every step', function (): void {
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation');

    // editField() sets both together and clearing resets both; neither is ever
    // written from the browser. Unlocked, a payload could name a field while
    // nulling the step, and every step owning that code would render the same
    // Filament schema against one state path.
    expect(fn () => $component->set('editingStepId', null))->toThrow(Exception::class)
        ->and(fn () => $component->set('editingFieldCode', 'name'))->toThrow(Exception::class);
});

it('ignores skipItem and focusItem against another tenant\'s proposal', function (): void {
    Bus::fake();
    $stranger = User::factory()->withPersonalTeam()->create();
    $foreignRecords = array_map(static fn (string $n): array => ['name' => $n], ['Contoso A', 'Contoso B']);
    $foreign = ProposalCardFixture::proposal($stranger, ['_batch' => true, 'records' => $foreignRecords], [
        'title' => 'Create Companies',
        'summary' => 'Create 2 companies',
        'items' => [['summary' => 'Contoso A', 'fields' => []], ['summary' => 'Contoso B', 'fields' => []]],
    ]);
    $own = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $own->getKey(), context: 'conversation')
        ->call('skipItem', (string) $foreign->getKey(), 0)
        ->assertNotDispatched('proposal:resolved')
        ->call('focusItem', (string) $foreign->getKey(), 1)
        ->assertSet('activeStepId', (string) $own->getKey());

    expect($foreign->fresh()->result_data)->toBeNull()
        ->and($foreign->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('never resolves a dock read from a client-supplied id, so another tenant cannot be read through it', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();
    $foreign = ProposalCardFixture::proposal(
        $stranger,
        ['name' => 'Contoso Holdings', 'account_owner_id' => (string) $stranger->getKey()],
        [
            'title' => 'Create Company',
            'summary' => 'Contoso Holdings',
            'fields' => [['label' => 'Name', 'code' => 'name', 'value' => 'Contoso Holdings']],
        ],
    );

    $own = ProposalCardFixture::proposal(
        $this->user,
        ['name' => 'Acme Corp', 'account_owner_id' => (string) $this->user->getKey()],
        [
            'title' => 'Create Company',
            'summary' => 'Acme Corp',
            'fields' => [['label' => 'Name', 'code' => 'name', 'value' => 'Acme Corp']],
        ],
    );

    // Livewire hands every client-invoked method through implicit route-model
    // binding, so a public method typed PendingAction would resolve this id with a
    // bare where(id)->first(). Each read below is called the way the browser calls
    // it, with the stranger's id in argument position.
    foreach (['currentRecordFields', 'editableCodes', 'recordCount', 'remainingCount'] as $method) {
        Livewire::test(ProposalCard::class, ['context' => 'conversation'])
            ->dispatch('proposal:set-active', id: $own->getKey(), context: 'conversation')
            ->call($method, $foreign->getKey())
            ->assertReturned(fn (mixed $returned): bool => ! str_contains(
                (string) json_encode($returned),
                'Contoso',
            ));
    }

    // The reads answer for the caller's own active step rather than returning
    // nothing, so the guard cannot be mistaken for the dock simply being broken.
    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $own->getKey(), context: 'conversation')
        ->call('currentRecordFields', $foreign->getKey())
        ->assertReturned(fn (array $fields): bool => collect($fields)->contains(
            fn (array $row): bool => ($row['value'] ?? null) === 'Acme Corp',
        ));
});
