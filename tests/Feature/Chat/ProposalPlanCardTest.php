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
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Events\PendingActionResolved;
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
            ->and(array_column($steps, 'entity_type'))->toBe(['company', 'people', 'task'])
            ->and(array_column($steps, 'recordLabel'))->toBe(['Northwind Traders', 'Priya Raman', 'Call Priya']);
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

    it('refuses to decide one step of a plan on its own', function (string $method): void {
        Bus::fake();
        [$company, $person, $task] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call($method, $person->getKey())
            ->assertHasNoErrors();

        expect(People::query()->count())->toBe(0)
            ->and([$company->refresh()->status, $person->refresh()->status, $task->refresh()->status])
            ->each->toBe(PendingActionStatus::Pending);
    })->with(['approveStep', 'rejectStep']);

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

    it('carries the recomputed outcome of every step in the plan with each announcement', function (): void {
        [$person, $task, $note] = ($this->chainedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey())
            ->call('approveAll')
            ->assertDispatched('proposal:resolved', function (string $event, array $params) use ($person, $task, $note): bool {
                $outcomes = array_map(fn (array $receipt): ?string => $receipt['outcome'], $params['receipts'] ?? []);

                return $params['pendingActionId'] === (string) $note->getKey()
                    && $outcomes === [
                        (string) $person->getKey() => 'left_out',
                        (string) $task->getKey() => 'left_out',
                        (string) $note->getKey() => 'applied',
                    ];
            });
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

        expect($dependents)->toBe([(string) $task->getKey() => [(string) $person->getKey()]]);
    });

    it('ignores a step id that is not in the plan', function (): void {
        [$person] = ($this->chainedPlan)();
        $stranger = ProposalCardFixture::proposal($this->user, ['name' => 'Unrelated'], ['title' => 't', 'summary' => 's', 'fields' => []]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $stranger->getKey())
            ->assertSet('leftOutSteps', [])
            ->call('keepLeftOut', [(string) $stranger->getKey(), ['nested']])
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

    it('refuses leave-out state written by the client', function (string $property): void {
        [$person] = ($this->chainedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->set($property, [(string) $person->getKey()]);
    })->with(['leftOutSteps', 'leftOutRows'])->throws(CannotUpdateLockedPropertyException::class);

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

describe('the change list', function (): void {
    beforeEach(function (): void {
        Bus::fake();

        $this->renderedPlan = function (): array {
            [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);

            $person = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [[
                'name' => 'Priya Nair',
                'custom_fields' => ['job_title' => 'VP Engineering', 'emails' => ['priya@northwind.test']],
            ]]);
            $task = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [[
                'title' => 'Call Priya',
                'assignee_ids' => [(string) $this->user->getKey()],
                'people_ids' => [PlanReference::to((string) $person->getKey())],
                'custom_fields' => ['due_date' => '2026-11-20'],
            ]]);
            $note = ProposalCardFixture::planProposal(CreateNoteTool::class, $conversationId, $turnId, [[
                'title' => 'Intro notes',
                'custom_fields' => ['body' => 'Met at the Northwind offsite and agreed to a follow-up call.'],
            ]]);

            return [$person, $task, $note];
        };
    });

    it('lists every value of every change under one decision', function (): void {
        [$person] = ($this->renderedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->assertSeeText('3 changes')
            ->assertSeeText('Approve 3 changes')
            ->assertSeeText('Discard all')
            ->assertSeeText('Priya Nair')
            ->assertSeeText('VP Engineering')
            ->assertSeeText('priya@northwind.test')
            ->assertSeeText('Call Priya')
            ->assertSeeText($this->user->name)
            ->assertSeeText('Nov 20, 2026')
            ->assertSeeText('Intro notes')
            ->assertSeeText('Met at the Northwind offsite')
            ->assertSeeHtml('data-proposal-ref')
            ->assertSeeText('new')
            ->assertDontSeeText('Approved together, in order')
            ->assertDontSeeText('(step 1)')
            ->assertSeeHtml('data-proposal-shortcut');
    });

    it('shows what a left-out step takes with it and recounts the primary', function (): void {
        [$person, $task] = ($this->renderedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey())
            ->assertSeeText('1 of 3 changes')
            ->assertSeeText('Priya Nair is left out, so this task is left out too. It links to Priya Nair.')
            ->assertSeeText('Keep Priya Nair')
            ->assertSeeText('Approve 1 change')
            ->call('toggleLeftOut', (string) $person->getKey())
            ->assertSeeText('Approve 3 changes')
            ->assertDontSeeText('is left out, so this task');

        expect($task->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('hides the primary when every approvable step is left out', function (): void {
        [$person, $task, $note] = ($this->renderedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->call('toggleLeftOut', (string) $person->getKey())
            ->call('toggleLeftOut', (string) $note->getKey())
            ->assertSeeText('Discard all')
            ->assertDontSeeText('Approve 0')
            ->assertDontSeeHtml('wire:click="approveAll"')
            ->assertDontSeeHtml('data-proposal-shortcut')
            ->call('createCurrentFromShortcut');

        expect($person->refresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($task->refresh()->status)->toBe(PendingActionStatus::Pending)
            ->and($note->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('clamps a value longer than 48 characters to two lines with a more control', function (): void {
        [$person] = ($this->renderedPlan)();

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->assertSeeHtml('line-clamp-2')
            ->assertSeeText('more');
    });

    it('names the direct link of each taken step, and its plural for a batch', function (): void {
        [$company, $person, $task] = ProposalCardFixture::planSteps($this->user);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('toggleLeftOut', (string) $company->getKey())
            ->assertSeeText('Northwind Traders is left out, so this person is left out too. It links to Northwind Traders.')
            ->assertSeeText('Priya Raman is left out with Northwind Traders, so this task is left out too. It links to Priya Raman.');

        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
        $batch = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Ada'], ['name' => 'Grace'], ['name' => 'Linus']]);
        ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [
            ['title' => 'Intro calls', 'people_ids' => [PlanReference::to((string) $batch->getKey(), 1)]],
        ]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $batch->getKey())
            ->call('toggleLeftOut', (string) $batch->getKey())
            ->assertSeeText('These 3 people are left out, so this task is left out too. It links to them.')
            ->assertSeeText('Keep these 3 people');
    });

    it('names the nearest left-out step on the chain and keeps that step from the note', function (array $leftOut, string $note, string $keep, int $toggles): void {
        $steps = ProposalCardFixture::planSteps($this->user);
        $ids = array_map(static fn (PendingAction $step): string => (string) $step->getKey(), $steps);

        $card = Livewire::test(ProposalCard::class)->call('setActive', $steps[0]->getKey());

        foreach ($leftOut as $position) {
            $card->call('toggleLeftOut', $ids[$position]);
        }

        preg_match_all('/<p[^>]*data-proposal-left-out-note.*?<\/p>/s', $card->html(), $notes);
        $taskNote = last($notes[0]);

        expect($taskNote)->toContain("{$note}, so this task is left out too. It links to Priya Raman.")
            ->and(trim(strip_tags(substr($taskNote, (int) strrpos($taskNote, '<button')))))->toBe("Keep {$keep}")
            ->and($taskNote)->toContain($ids[$toggles])
            ->and(array_filter(array_diff_key($ids, [$toggles => true]), static fn (string $id): bool => str_contains($taskNote, $id)))->toBe([]);
    })->with([
        'only the first step left out' => [[0], 'Priya Raman is left out with Northwind Traders', 'Northwind Traders', 0],
        'the first and the middle step left out' => [[0, 1], 'Priya Raman is left out', 'Priya Raman', 1],
    ]);

    it('lets one Keep bring back every left-out step a taken step links through', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
        $company = ProposalCardFixture::planProposal(CreateCompanyTool::class, $conversationId, $turnId, [['name' => 'Northwind Traders']]);
        $person = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Priya Raman']]);
        $task = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [[
            'title' => 'Call Priya',
            'company_ids' => [PlanReference::to((string) $company->getKey())],
            'people_ids' => [PlanReference::to((string) $person->getKey())],
        ]]);

        $card = Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->call('toggleLeftOut', (string) $company->getKey())
            ->assertSeeText('Keep Northwind Traders')
            ->assertDontSeeText('Keep Northwind Traders and Priya Raman')
            ->call('toggleLeftOut', (string) $person->getKey())
            ->assertSeeText('Keep Northwind Traders and Priya Raman');

        expect($card->instance()->leftOutDependents())->toBe([(string) $task->getKey() => [(string) $company->getKey(), (string) $person->getKey()]]);

        $card->call('keepLeftOut', [(string) $company->getKey(), (string) $person->getKey()])
            ->assertSet('leftOutSteps', [])
            ->assertSeeText('Approve 3 changes')
            ->assertDontSeeText('is left out');

        expect($task->refresh()->status)->toBe(PendingActionStatus::Pending);
    });

    it('gives a linked row its x back once the step that links to it is left out', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
        $batch = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Ada'], ['name' => 'Grace']]);
        $task = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [
            ['title' => 'Call Grace', 'people_ids' => [PlanReference::to((string) $batch->getKey(), 1)]],
        ]);
        $stepId = (string) $batch->getKey();

        $component = Livewire::test(ProposalCard::class)
            ->call('setActive', $batch->getKey())
            ->assertSeeText('linked from')
            ->call('toggleLeftOut', (string) $task->getKey())
            ->assertDontSeeText('linked from')
            ->call('toggleLeftOutRow', $stepId, 1)
            ->assertSet('leftOutRows', [$stepId => [1]]);

        expect($component->instance()->linkedRowIndexes())->toBe([]);
    });

    it('writes a linked row again once the step that links to it is kept', function (): void {
        Bus::fake();
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
        $batch = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Ada Fp'], ['name' => 'Grace Fp']]);
        $task = ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [
            ['title' => 'Call Grace', 'people_ids' => [PlanReference::to((string) $batch->getKey(), 1)]],
        ]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $batch->getKey())
            ->call('toggleLeftOut', (string) $task->getKey())
            ->call('toggleLeftOutRow', (string) $batch->getKey(), 1)
            ->call('toggleLeftOut', (string) $task->getKey())
            ->assertSeeText('Approve 3 changes')
            ->call('approveAll');

        expect(People::query()->whereIn('name', ['Ada Fp', 'Grace Fp'])->count())->toBe(2)
            ->and(Task::query()->where('title', 'Call Grace')->exists())->toBeTrue();
    });

    it('draws a chip only for a record the plan links to, never for free text', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
        $company = ProposalCardFixture::planProposal(CreateCompanyTool::class, $conversationId, $turnId, [['name' => 'Acme, Inc.']]);
        ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [[
            'name' => 'Priya Nair',
            'company_id' => PlanReference::to((string) $company->getKey()),
            'custom_fields' => ['job_title' => 'Lead (step 2)'],
        ]]);

        $html = Livewire::test(ProposalCard::class)
            ->call('setActive', $company->getKey())
            ->assertSeeText('Lead (step 2)')
            ->assertSeeText('Acme, Inc.')
            ->html();

        expect(substr_count($html, 'data-proposal-ref'))->toBe(1);
    });

    it('writes a create line in sentence case and leaves out what it does not set', function (): void {
        [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
        $person = ProposalCardFixture::planProposal(CreatePersonTool::class, $conversationId, $turnId, [['name' => 'Priya Nair']]);
        ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, [['title' => 'Call Priya']]);

        Livewire::test(ProposalCard::class)
            ->call('setActive', $person->getKey())
            ->assertSeeText('Create person')
            ->assertSeeText('Create task')
            ->assertDontSeeText('Create Person')
            ->assertDontSeeText('(none)');
    });
});

it('broadcasts a full plan of batches small enough for Reverb, naming only the rows left out', function (): void {
    Bus::fake();
    Event::fake([PendingActionResolved::class]);
    [$conversationId, $turnId] = ProposalCardFixture::startPlan($this->user);
    $steps = array_map(
        fn (int $step): PendingAction => ProposalCardFixture::planProposal(CreateTaskTool::class, $conversationId, $turnId, array_map(
            fn (int $row): array => ['title' => "Step {$step} task {$row}"],
            range(1, 25),
        )),
        range(1, (int) config('chat.max_plan_steps')),
    );

    Livewire::test(ProposalCard::class)
        ->call('setActive', $steps[0]->getKey())
        ->call('toggleLeftOutRow', (string) $steps[0]->getKey(), 3)
        ->call('approveAll');

    $final = Event::dispatched(PendingActionResolved::class)->map(fn (array $args): PendingActionResolved => $args[0])->last();
    $receipts = $final->broadcastWith()['receipts'];

    expect(strlen((string) json_encode($final->broadcastWith())))->toBeLessThan(4000)
        ->and($receipts)->toHaveCount(count($steps))
        ->and($receipts[(string) $steps[0]->getKey()]['itemResults'])->toBe(['3' => ['status' => 'skipped', 'outcome' => 'left_out', 'excluded' => []]])
        ->and($receipts[(string) $steps[1]->getKey()]['itemResults'])->toBe([])
        ->and(Task::query()->where('title', 'like', 'Step %')->count())->toBe(count($steps) * 25 - 1);
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

it('ignores a left-out row and focusItem against another tenant\'s proposal', function (): void {
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
        ->call('toggleLeftOutRow', (string) $foreign->getKey(), 0)
        ->assertSet('leftOutRows', [])
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
