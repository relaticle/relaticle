<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Note;
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
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\Note\CreateNoteTool;
use Relaticle\Chat\Tools\People\CreatePersonTool;
use Relaticle\Chat\Tools\Task\CreateTaskTool;
use Tests\Helpers\ProposalCardFixture;

mutates(ProposalCard::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
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

describe('leaving steps and rows out', function (): void {
    beforeEach(function (): void {
        Bus::fake();

        $this->chainedPlan = function (): array {
            [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

            $person = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Priya Raman']]);
            $task = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [
                ['title' => 'Call Priya', 'people_ids' => [PlanReference::to((string) $person->getKey())]],
            ]);
            $note = ProposalCardFixture::planProposal(CreateNoteTool::class, $conversationId, $turnId, [['title' => 'Intro notes']]);

            return [$person, $task, $note];
        };
    });

    it('rejects a left-out step and its dependents and approves the rest', function (): void {
        [$person, $task, $note] = ($this->chainedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey())
            ->call('approveAll')
            ->assertHasNoErrors();

        expect($person->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($task->result_data['cancelled_by'])->toBe((string) $person->getKey())
            ->and($note->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and(People::query()->count())->toBe(0)
            ->and(Task::query()->count())->toBe(0)
            ->and(Note::query()->where('title', 'Intro notes')->count())->toBe(1);
    });

    it('announces every step the approval rejected', function (): void {
        [$person, $task] = ($this->chainedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey())
            ->call('approveAll')
            ->assertDispatched('proposal:resolved', pendingActionId: (string) $person->getKey(), decision: 'rejected')
            ->assertDispatched('proposal:resolved', pendingActionId: (string) $task->getKey(), decision: 'rejected', cancelledBy: (string) $person->getKey());
    });

    it('writes nothing until approve when a step is left out', function (): void {
        [$person, $task, $note] = ($this->chainedPlan)();

        $component = Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey());

        expect($person->refresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($note->refresh()->status)->toBe(PendingActionStatus::Pending);

        $component->call('toggleLeftOut', (string) $person->getKey())
            ->assertSet('leftOutSteps', []);

        expect($component->instance()->leftOutDependents())->toBe([]);

        $component->call('approveAll');

        expect($person->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($note->refresh()->status)->toBe(PendingActionStatus::Approved);
    });

    it('reports the dependents a left-out step takes', function (): void {
        [$person, $task] = ($this->chainedPlan)();

        $dependents = Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey())
            ->instance()
            ->leftOutDependents();

        expect($dependents)->toBe([(string) $task->getKey() => (string) $person->getKey()]);
    });

    it('ignores a step id that is not in the plan', function (): void {
        [$person] = ($this->chainedPlan)();
        $stranger = ProposalCardFixture::proposal($this->user, ['name' => 'Unrelated'], ['title' => 't', 'summary' => 's', 'fields' => []]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $stranger->getKey())
            ->assertSet('leftOutSteps', [])
            ->set('leftOutSteps', [(string) $stranger->getKey()])
            ->call('approveAll');

        expect($stranger->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('counts records not steps', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

        $batch = ProposalCardFixture::planProposal(CreateCompanyTool::class, $conversationId, $turnId, array_map(
            static fn (int $number): array => ['name' => "Company {$number}"],
            range(1, 6),
        ));
        ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [['title' => 'Review the list']]);

        $component = Livewire::test(ProposalCard::class)->call('setActive', $batch->getKey());

        expect($component->instance()->approvableRecordCount())->toBe(7);

        $component->call('toggleLeftOutRow', (string) $batch->getKey(), 2);

        expect($component->instance()->approvableRecordCount())->toBe(6);
    });

    it('never leaves a left-out step pending after a failed approval', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

        $first = ProposalCardFixture::planProposal(CreateCompanyTool::class, $conversationId, $turnId, [['name' => 'Left Out Inc']]);
        $second = ProposalCardFixture::planProposal(CreateNoteTool::class, $conversationId, $turnId, [['title' => 'Kept note']]);
        $third = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [['title' => 'Fails']]);
        $third->update(['action_class' => 'stdClass']);

        $component = Livewire::test(ProposalCard::class)
            ->call('setActive', $first->getKey())
            ->call('toggleLeftOut', (string) $first->getKey())
            ->call('approveAll')
            ->assertHasErrors('resolve')
            ->assertSet('leftOutSteps', []);

        expect($first->refresh()->status)->toBe(PendingActionStatus::Rejected)
            ->and($second->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($third->refresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($component->instance()->planSteps())->toHaveCount(1)
            ->and(Company::query()->count())->toBe(0);
    });

    it('has no leave-out for a row a later step links to', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

        $batch = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Priya Raman'], ['name' => 'Dev Patel']]);
        $task = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [
            ['title' => 'Call Dev', 'people_ids' => [PlanReference::to((string) $batch->getKey(), 1)]],
        ]);

        $component = Livewire::test(ProposalCard::class)
            ->call('setActive', $batch->getKey())
            ->call('toggleLeftOutRow', (string) $batch->getKey(), 1)
            ->assertSet('leftOutRows', []);

        expect($component->instance()->linkedRowIndexes())->toBe([
            (string) $batch->getKey() => [1 => 'Create task "Call Dev"'],
        ]);

        $component->call('toggleLeftOutRow', (string) $batch->getKey(), 0)
            ->assertSet('leftOutRows', [(string) $batch->getKey() => [0]])
            ->call('approveAll');

        expect(People::query()->pluck('name')->all())->toBe(['Dev Patel'])
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Approved);
    });

    it('approves a batch whose last row is left out', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

        $batch = ProposalCardFixture::planProposal(CreateCompanyTool::class, $conversationId, $turnId, [['name' => 'Alpha'], ['name' => 'Beta'], ['name' => 'Gamma']]);
        ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [['title' => 'Review']]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $batch->getKey())
            ->call('toggleLeftOutRow', (string) $batch->getKey(), 2)
            ->call('approveAll')
            ->assertHasNoErrors();

        expect(Company::query()->orderBy('name')->pluck('name')->all())->toBe(['Alpha', 'Beta'])
            ->and($batch->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and(Task::query()->where('title', 'Review')->exists())->toBeTrue();
    });

    it('ignores malformed leave-out state sent by the client', function (mixed $malformed): void {
        [$person, $task, $note] = ($this->chainedPlan)();

        $component = Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->set('leftOutRows', $malformed)
            ->set('leftOutSteps', $malformed);

        expect($component->instance()->approvableRecordCount())->toBe(3)
            ->and($component->instance()->leftOutDependents())->toBe([]);

        $component->call('toggleLeftOutRow', (string) $person->getKey(), 0)
            ->call('approveAll')
            ->assertHasNoErrors();

        expect($person->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($note->refresh()->status)->toBe(PendingActionStatus::Approved);
    })->with([
        'a string' => ['x'],
        'a string value' => [['x' => 'y']],
        'a string list' => [['x', 'y']],
        'a nested non-int list' => [['x' => ['a', 1.5, null]]],
    ]);

    it('rejects the left-out rows of a batch step and approves the others', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

        $batch = ProposalCardFixture::planProposal(CreateCompanyTool::class, $conversationId, $turnId, [['name' => 'Alpha'], ['name' => 'Beta'], ['name' => 'Gamma']]);
        ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [['title' => 'Review']]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $batch->getKey())
            ->call('toggleLeftOutRow', (string) $batch->getKey(), 1)
            ->call('approveAll')
            ->assertDispatched('proposal:resolved', pendingActionId: (string) $batch->getKey(), index: 1, decision: 'rejected');

        expect(Company::query()->orderBy('name')->pluck('name')->all())->toBe(['Alpha', 'Gamma'])
            ->and($batch->refresh()->status)->toBe(PendingActionStatus::Approved)
            ->and($batch->result_data['items'][1]['status'])->toBe('rejected');
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
    $stranger = User::factory()->withPersonalWorkspace()->create();
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
    $stranger = User::factory()->withPersonalWorkspace()->create();
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

it('tells an approver demoted since the plan was proposed that their role no longer allows it', function (): void {
    Bus::fake();

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member->fresh());
    [$company] = ProposalCardFixture::planSteps($member->fresh());

    $this->workspace->users()->updateExistingPivot($member->getKey(), ['role' => WorkspaceRole::Viewer->value]);
    $this->actingAs($member->fresh());

    $component = Livewire::test(ProposalCard::class)
        ->call('setActive', $company->getKey())
        ->call('approveAll');

    expect($component->errors()->get('resolve'))->toBe([
        __(':step could not be completed: :message', ['step' => 'Create company "Northwind Traders"', 'message' => __('You no longer have permission to make this change.')]),
    ])
        ->and(Company::query()->where('name', 'Northwind Traders')->exists())->toBeFalse();
});
