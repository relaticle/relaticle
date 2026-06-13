<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Data\VisibilityConditionData;
use Relaticle\CustomFields\Data\VisibilityData;
use Relaticle\CustomFields\Enums\ConditionSource;
use Relaticle\CustomFields\Enums\VisibilityMode;
use Relaticle\CustomFields\Enums\VisibilityOperator;
use Spatie\LaravelData\DataCollection;

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

/**
 * @param  list<string>  $names
 */
function makeBatchCompanyProposal(User $user, array $names): PendingAction
{
    $records = array_map(static fn (string $name): array => ['name' => $name], $names);
    $items = array_map(static fn (string $name): array => [
        'title' => $name,
        'summary' => "Create company \"{$name}\"",
        'fields' => [['label' => 'Name', 'value' => $name]],
    ], $names);

    return proposalCardPa(
        $user,
        ['_batch' => true, 'records' => $records],
        ['title' => 'Create Companies', 'summary' => 'Create '.count($names).' companies', 'items' => $items],
    );
}

/**
 * @param  array<string, mixed>  $actionData
 */
function makeTaskProposal(User $user, array $actionData): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => $actionData,
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

/**
 * The seeded task `status` field is a SINGLE_CHOICE with To do / In progress /
 * Done options, created for every team by the CreateTeamCustomFields listener.
 *
 * @return array{0: CustomField, 1: list<string>}
 */
function seedTaskSingleChoiceField(mixed $team): array
{
    $status = CustomField::query()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->with('options')
        ->first();

    expect($status)->not->toBeNull('seeded task status field is required for this test');

    $optionIds = $status->options->map(fn (mixed $option): string => (string) $option->id)->values()->all();

    expect($optionIds)->not->toBeEmpty();

    return [$status, $optionIds];
}

/**
 * Build a task custom field that is only visible when the seeded `status`
 * field equals "Done" — a cross-field (sibling) visibility condition. Under
 * `->only([$code])` the sibling is absent from the scoped form, so the
 * condition must fail open rather than throw.
 */
function seedTaskFieldWithVisibilityCondition(mixed $team): CustomField
{
    [$status] = seedTaskSingleChoiceField($team);

    return CustomField::query()->create([
        'tenant_id' => $team->getKey(),
        'entity_type' => 'task',
        'code' => 'completion_note',
        'name' => 'Completion note',
        'type' => 'text',
        'sort_order' => 99,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData(
            visibility: new VisibilityData(
                mode: VisibilityMode::SHOW_WHEN,
                conditions: new DataCollection(VisibilityConditionData::class, [
                    new VisibilityConditionData(
                        field_code: $status->code,
                        operator: VisibilityOperator::EQUALS,
                        value: 'Done',
                        source: ConditionSource::CustomField,
                    ),
                ]),
            ),
        ),
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
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('pendingActionId', $action->getKey())
        ->assertSee('Create company "Acme Corp"')
        ->assertSee('Acme Corp');
});

it('refuses a pending action from another tenant', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = proposalCardPa($other, ['name' => 'Foreign'], ['title' => 'x', 'summary' => 'x', 'fields' => []]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $foreign->getKey(), context: 'conversation')
        ->assertSet('pendingActionId', null);
});

it('ignores set-active events targeted at a different chat context', function (): void {
    $action = proposalCardPa($this->user, ['name' => 'Acme'], ['title' => 't', 'summary' => 's', 'fields' => []]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'side-panel')
        ->assertSet('pendingActionId', null);
});

it('steps between batch records and clamps at the ends', function (): void {
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta', 'Gamma']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('cursor', 0)
        ->call('stepNext')->assertSet('cursor', 1)
        ->call('stepNext')->assertSet('cursor', 2)
        ->call('stepNext')->assertSet('cursor', 2)
        ->call('stepPrev')->assertSet('cursor', 1);
});

it('starts the cursor at the first unresolved record', function (): void {
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta', 'Gamma']);
    $action->update(['result_data' => ['items' => ['0' => ['status' => 'approved']]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('cursor', 1);
});

it('does not surface an expired pending action', function (): void {
    $action = proposalCardPa($this->user, ['name' => 'Stale'], ['title' => 't', 'summary' => 's', 'fields' => []]);
    $action->update(['expires_at' => now()->subMinute()]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('pendingActionId', null);
});

it('creates the current batch record and advances to the next unresolved', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved')
        ->assertSet('cursor', 1);

    expect(Company::query()->where('team_id', $this->team->getKey())->pluck('name')->all())
        ->toContain('Alpha')->not->toContain('Beta');
    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('emits will-resolve with willFinalize false when the first of many items is created', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:will-resolve', willFinalize: false, context: 'conversation');
});

it('creates the single proposal record and collapses the dock', function (): void {
    Bus::fake();
    $action = proposalCardPa($this->user,
        ['name' => 'Acme Corp'],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [['label' => 'Name', 'value' => 'Acme Corp']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:will-resolve', willFinalize: true, context: 'conversation')
        ->assertDispatched('proposal:resolved')
        ->assertSet('pendingActionId', null);

    expect(Company::query()->where('team_id', $this->team->getKey())->where('name', 'Acme Corp')->exists())->toBeTrue();
    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('finalizes the batch on the last item and collapses the dock', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);
    $action->update(['result_data' => ['items' => ['0' => ['status' => 'approved', 'id' => 'x']], 'ids' => ['x']]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('cursor', 1)
        ->call('createCurrent')
        ->assertDispatched('proposal:will-resolve', willFinalize: true, context: 'conversation')
        ->assertDispatched('proposal:resolved')
        ->assertSet('pendingActionId', null);

    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved);
    Bus::assertDispatched(ContinueChatMessage::class, 1);
});

it('discards the current batch record and advances to the next unresolved', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('discardCurrent')
        ->assertDispatched('proposal:resolved')
        ->assertSet('cursor', 1);

    expect(Company::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('finalizes after the last record is resolved and dispatches one continuation', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->call('discardCurrent')
        ->assertSet('pendingActionId', null);

    Bus::assertDispatched(ContinueChatMessage::class, 1);
    expect($action->fresh()->status)->not->toBe(PendingActionStatus::Pending);
});

it('marks a fully-discarded batch as rejected', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('discardCurrent')
        ->call('discardCurrent');

    expect($action->fresh()->status)->toBe(PendingActionStatus::Rejected);
    expect(Company::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('emits proposal:resolve-failed and does not advance when the service rejects the resolution', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->set('cursor', 99) // out-of-range -> approveItem's index guard throws RuntimeException
        ->call('createCurrent')
        ->assertDispatched('proposal:resolve-failed')
        ->assertNotDispatched('proposal:resolved')
        ->assertSet('pendingActionId', $action->getKey()); // not cleared

    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    expect(Company::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('does nothing when createCurrent is called while a field edit is open', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->set('editingFieldCode', 'name')
        ->call('createCurrent')
        ->assertNotDispatched('proposal:will-resolve');

    expect(Company::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('routes the create-current shortcut to the current record for the matching context', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->dispatch('proposal:create-current', context: 'conversation')
        ->assertSet('cursor', 1);

    expect(Company::query()->where('team_id', $this->team->getKey())->pluck('name')->all())->toContain('Alpha');
});

it('ignores the create-current shortcut for a different context', function (): void {
    Bus::fake();
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->dispatch('proposal:create-current', context: 'side-panel')
        ->assertSet('cursor', 0);

    expect(Company::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('renders the current record and advances the shown record with the stepper', function (): void {
    $action = makeBatchCompanyProposal($this->user, ['Alpha', 'Beta', 'Gamma']);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSee('Alpha')
        ->assertDontSee('Beta');

    $component->call('stepNext')
        ->assertSee('Beta')
        ->assertDontSee('Alpha');
});

it('renders a single (non-batch) proposal without a stepper', function (): void {
    $action = proposalCardPa($this->user, ['name' => 'Solo Inc'], ['title' => 'Create Company', 'summary' => 'Solo Inc', 'fields' => [['label' => 'Name', 'value' => 'Solo Inc']]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSee('Solo Inc')
        ->assertSee('Create');
});

it('builds the real custom-field component for the edited field, prefilled from action_data', function (): void {
    [$field, $optionIds] = seedTaskSingleChoiceField($this->team);
    $action = makeTaskProposal($this->user, ['title' => 'Edit me', 'custom_fields' => [$field->code => $optionIds[0]]]);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $field->code)
        ->assertSet('editingFieldCode', $field->code)
        ->assertSet("data.custom_fields.{$field->code}", $optionIds[0])
        ->assertHasNoErrors();

    $expectedName = "custom_fields.{$field->code}";
    $flat = $component->instance()->form->getFlatComponents();
    $built = collect($flat)->first(fn (mixed $c): bool => $c instanceof Field && $c->getName() === $expectedName);

    expect($built)->not->toBeNull('the scoped Filament custom-field component should be built into the form');
});

it('does not throw building a field with a cross-field visibility condition (fails open under ->only())', function (): void {
    $dependent = seedTaskFieldWithVisibilityCondition($this->team);
    $action = makeTaskProposal($this->user, ['title' => 'T', 'custom_fields' => []]);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $dependent->code)
        ->assertSet('editingFieldCode', $dependent->code)
        ->assertHasNoErrors();

    $flat = $component->instance()->form->getFlatComponents();
    $built = collect($flat)->first(fn (mixed $c): bool => $c instanceof Field
        && $c->getName() === "custom_fields.{$dependent->code}");

    expect($built)->not->toBeNull('the field with a sibling visibility condition should still build under ->only()');
});

it('saves an edited custom field through ProposalEditor without executing', function (): void {
    Bus::fake([ContinueChatMessage::class]);
    [$field, $optionIds] = seedTaskSingleChoiceField($this->team);
    $action = makeTaskProposal($this->user, ['title' => 'T', 'custom_fields' => [$field->code => $optionIds[0]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $field->code)
        ->set("data.custom_fields.{$field->code}", $optionIds[1])
        ->call('saveField')
        ->assertSet('editingFieldCode', null)
        ->assertHasNoErrors();

    $fresh = $action->fresh();
    expect($fresh->status)->toBe(PendingActionStatus::Pending)
        ->and($fresh->action_data['custom_fields'][$field->code])->toBe($optionIds[1]);
    expect(Task::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
    Bus::assertNotDispatched(ContinueChatMessage::class);
});

it('cancels an inline edit without persisting and leaves action_data untouched', function (): void {
    [$field, $optionIds] = seedTaskSingleChoiceField($this->team);
    $action = makeTaskProposal($this->user, ['title' => 'Keep me', 'custom_fields' => [$field->code => $optionIds[0]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $field->code)
        ->set("data.custom_fields.{$field->code}", $optionIds[1]) // change the working value...
        ->call('cancelField')                                     // ...then cancel
        ->assertSet('editingFieldCode', null);

    expect($action->fresh()->action_data['custom_fields'][$field->code])->toBe($optionIds[0]);
});

it('edits a core text field (title) in place and persists it via applyEdit without executing', function (): void {
    Bus::fake([ContinueChatMessage::class]);
    $action = makeTaskProposal($this->user, ['title' => 'Old Title']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', 'title')
        ->assertSet('editingFieldCode', 'title')
        ->assertSet('data.title', 'Old Title')
        ->set('data.title', 'New Title')
        ->call('saveField')
        ->assertSet('editingFieldCode', null)
        ->assertHasNoErrors();

    $fresh = $action->fresh();
    expect($fresh->status)->toBe(PendingActionStatus::Pending)
        ->and($fresh->action_data['title'])->toBe('New Title');
    expect(Task::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
    Bus::assertNotDispatched(ContinueChatMessage::class);
});
