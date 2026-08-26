<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
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

it('keeps a batch open when the active record fails and keeps the earlier commits', function (): void {
    Bus::fake();
    $company = Company::factory()->for($this->team)->create(['name' => 'Old name']);

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Company\\UpdateCompany',
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'company',
        'action_data' => [
            '_batch' => true,
            'records' => [
                ['_record_id' => (string) $company->getKey(), '_model_class' => Company::class, 'name' => 'New name'],
                ['_record_id' => '01J00000000000000000000000', '_model_class' => Company::class, 'name' => 'Ghost'],
            ],
        ],
        'display_data' => [
            'title' => 'Update 2 companies',
            'summary' => 'Update 2 companies',
            'items' => [
                ['summary' => 'Update company "Old name"', 'fields' => []],
                ['summary' => 'Update company "Ghost"', 'fields' => []],
            ],
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed');

    // The first record committed; the vanished one failed its own approval
    // without undoing it, and the batch stays open on the failing record.
    expect($company->fresh()->name)->toBe('New name')
        ->and($action->fresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($action->fresh()->result_data['items'][0]['status'] ?? null)->toBe('approved');
});

it('resolves a delete batch per item through the component, deleting only approved records', function (): void {
    Bus::fake();
    $tasks = Task::factory()->count(2)->for($this->team)->create();

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\DeleteTask',
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'task',
        'action_data' => [
            '_batch' => true,
            'records' => $tasks->map(fn (Task $t): array => ['_record_id' => $t->getKey(), '_model_class' => Task::class])->all(),
        ],
        'display_data' => [
            'title' => 'Delete 2 Tasks',
            'summary' => 'Delete 2 tasks',
            'items' => $tasks->map(fn (Task $t): array => [
                'summary' => "Delete Task \"{$t->title}\"",
                'fields' => [['label' => 'Name', 'value' => $t->title]],
            ])->all(),
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('skipItem', (string) $action->getKey(), 1); // keep task 1

    // The batch is not yet finalized: item 1 is skipped, item 0 still pending.
    expect(Task::query()->whereKey($tasks[1]->getKey())->exists())->toBeTrue()
        ->and(Task::query()->whereKey($tasks[0]->getKey())->exists())->toBeTrue()
        ->and($action->fresh()->status)->toBe(PendingActionStatus::Pending);

    $component->call('createCurrent'); // delete the remaining item 0 -> finalize

    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved)
        ->and(Task::query()->whereKey($tasks[0]->getKey())->exists())->toBeFalse()
        ->and(Task::query()->whereKey($tasks[1]->getKey())->exists())->toBeTrue();
});

it('offers no inline-edit codes for a delete proposal', function (): void {
    $task = Task::factory()->for($this->team)->create();

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\DeleteTask',
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'task',
        'action_data' => ['_record_ids' => [$task->getKey()], '_model_class' => Task::class],
        'display_data' => ['title' => 'Delete Task', 'summary' => "Delete Task \"{$task->title}\"", 'fields' => [['label' => 'Name', 'value' => $task->title]]],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $codes = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->instance()->editableCodes();

    expect($codes)->toBe([]);
});

it('surfaces a failure when the assignee left the workspace between proposal and approval', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Follow up call', 'assignee_ids' => [(string) $member->getKey()]],
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task "Follow up call"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $this->team->users()->detach($member);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed')
        ->assertHasErrors('resolve');

    expect(Task::query()->where('title', 'Follow up call')->exists())->toBeFalse()
        ->and($action->fresh()->status)->toBe(PendingActionStatus::Pending)
        // The reason survives on the row, so the model can explain the failure
        // after the proposal is eventually decided.
        ->and($action->fresh()->result_data['last_error'] ?? null)->toBeString()->not->toBe('');
});

it('renders the resolve failure in the dock so the approval is never a silent no-op', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Ghost assignee', 'assignee_ids' => [(string) User::factory()->create()->getKey()]],
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task "Ghost assignee"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertSee('not in your workspace');
});

it('approving a custom field proposal dispatches a record link to the management page', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::customField($this->user, 'people', 'Age', 'number');

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved', function (string $event, array $params): bool {
            $record = $params['record'] ?? null;

            return is_array($record)
                && $record['type'] === 'custom_field'
                && $record['label'] === 'Age'
                && str_contains((string) $record['url'], 'currentEntityType=people');
        });
});

it('surfaces a readable error on the card when the field name was taken after the proposal', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::customField($this->user, 'people', 'Age', 'number');

    CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $this->team->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed')
        ->assertHasErrors('resolve');

    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('never puts a database error message on the card or in the transcript', function (): void {
    Bus::fake();

    // companies.name is NOT NULL and CreateCompany does not validate it, so an
    // approval carrying a stale/emptied name reaches Postgres and raises a
    // QueryException, whose getMessage() carries the statement, the failing row
    // and the connection host.
    $action = ProposalCardFixture::proposal($this->user, ['name' => null], ['title' => 'Create Company', 'summary' => 'Create a company']);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed')
        ->assertNotDispatched('proposal:resolved');

    $errors = $component->errors()->get('resolve');
    $shown = implode(' ', $errors);

    expect($errors)->not->toBeEmpty()
        ->and($shown)->not->toContain('SQLSTATE')
        ->and($shown)->not->toContain('insert into')
        ->and($shown)->not->toContain('Connection: pgsql')
        ->and($shown)->not->toContain('companies')
        ->and($shown)->toBe('This change could not be saved. Please try again.');

    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    expect(Company::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});
