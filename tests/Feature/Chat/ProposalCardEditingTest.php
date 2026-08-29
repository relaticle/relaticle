<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Features\OnboardSeed;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\Task\CreateTaskTool;
use Tests\Helpers\ProposalCardFixture;

mutates(ProposalCard::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

it('builds the real custom-field component for the edited field, prefilled from action_data', function (): void {
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'Edit me', 'custom_fields' => [$field->code => $optionIds[0]]]);

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
    $dependent = ProposalCardFixture::taskFieldWithVisibilityCondition($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'T', 'custom_fields' => []]);

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
    Bus::fake();
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'T', 'custom_fields' => [$field->code => $optionIds[0]]]);

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
});

it('preserves other custom fields when only one is edited', function (): void {
    Bus::fake();
    [$status, $statusOptionIds] = ProposalCardFixture::seededTaskChoice($this->team);

    $priority = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'priority')
        ->with('options')
        ->first();
    expect($priority)->not->toBeNull('seeded task priority field is required for this test');
    $priorityOptionId = (string) $priority->options->first()->id;

    $action = ProposalCardFixture::task($this->user, [
        'title' => 'T',
        'custom_fields' => [
            $status->code => $statusOptionIds[0],
            $priority->code => $priorityOptionId,
        ],
    ]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $status->code)
        ->set("data.custom_fields.{$status->code}", $statusOptionIds[1])
        ->call('saveField')
        ->assertSet('editingFieldCode', null)
        ->assertHasNoErrors();

    $fresh = $action->fresh();
    expect($fresh->action_data['custom_fields'][$status->code])->toBe($statusOptionIds[1])
        ->and($fresh->action_data['custom_fields'][$priority->code])->toBe($priorityOptionId);
});

it('cancels an inline edit without persisting and leaves action_data untouched', function (): void {
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'Keep me', 'custom_fields' => [$field->code => $optionIds[0]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $field->code)
        ->set("data.custom_fields.{$field->code}", $optionIds[1]) // change the working value...
        ->call('cancelField')                                     // ...then cancel
        ->assertSet('editingFieldCode', null);

    expect($action->fresh()->action_data['custom_fields'][$field->code])->toBe($optionIds[0]);
});

it('edits a core text field (title) in place and persists it via applyEdit without executing', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::task($this->user, ['title' => 'Old Title']);

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
});

it('rejects an out-of-options choice value at the form layer without persisting', function (): void {
    Bus::fake();
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'T', 'custom_fields' => [$field->code => $optionIds[0]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', $field->code)
        ->set("data.custom_fields.{$field->code}", 'not-an-option-id')
        ->call('saveField')
        ->assertSet('editingFieldCode', $field->code)
        ->assertHasErrors("data.custom_fields.{$field->code}");

    expect($action->fresh()->action_data['custom_fields'][$field->code])->toBe($optionIds[0]);
});

it('rejects an empty required core name at the form layer and keeps the proposal pending', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::proposal(
        $this->user,
        ['name' => 'Acme Corp', 'account_owner_id' => (string) $this->user->getKey()],
        ['title' => 'Create Company', 'summary' => 'Acme Corp', 'fields' => [['label' => 'Name', 'value' => 'Acme Corp']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', 'name')
        ->set('data.name', '   ')
        ->call('saveField')
        ->assertSet('editingFieldCode', 'name')
        ->assertHasErrors('data.name');

    $fresh = $action->fresh();
    expect($fresh->status)->toBe(PendingActionStatus::Pending)
        ->and($fresh->action_data['name'])->toBe('Acme Corp');
});

it('exposes editable codes for the entity (core keys + non-deferred custom fields)', function (): void {
    [$field] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'T', 'custom_fields' => [$field->code => null]]);

    $codes = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->instance()->editableCodes();

    expect($codes)->toContain('title')
        ->and($codes)->toContain($field->code);
});

it('omits deferred custom fields (file upload, record lookup) from the editable codes', function (): void {
    $fileField = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'attachment',
        'name' => 'Attachment',
        'type' => CustomFieldType::FILE_UPLOAD->value,
        'sort_order' => 90,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    // A record-lookup field resolves to a MULTI_CHOICE data type, so kindFor()
    // would otherwise admit it as a 'multiselect'. Only isDeferred()'s RECORD /
    // lookup_type branch keeps it out. This is the row that makes the deferral
    // load-bearing (the file-upload type is disabled in config, so it is excluded
    // by the kindFor() fallback regardless).
    $recordField = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => CustomFieldType::RECORD->value,
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    $action = ProposalCardFixture::task($this->user, ['title' => 'T', 'custom_fields' => []]);

    $codes = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->instance()->editableCodes();

    expect($codes)->toContain('title')
        ->and($codes)->not->toContain($fileField->code)
        ->and($codes)->not->toContain($recordField->code);
});

it('rebuilds the current record fields with codes on editable rows and no divergence from stored display', function (): void {
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $optionLabel = (string) $field->options->firstWhere('id', $optionIds[0])->name;

    $tool = resolve(CreateTaskTool::class);
    $tool->setConversationId(null);
    $tool->handle(new Request(['records' => [['title' => 'My Task', 'custom_fields' => [$field->code => $optionLabel]]]]));

    $action = PendingAction::query()->latest()->firstOrFail();

    $fields = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->instance()->currentRecordFields();

    $titleRow = collect($fields)->firstWhere('label', 'Title');
    expect($titleRow['code'] ?? null)->toBe('title');

    $customRow = collect($fields)->firstWhere('code', $field->code);
    expect($customRow)->not->toBeNull();

    $stored = $action->display_data['fields'] ?? ($action->display_data['items'][0]['fields'] ?? []);

    expect(collect($fields)->pluck('label')->all())->toBe(collect($stored)->pluck('label')->all())
        ->and(collect($fields)->pluck('value')->all())->toBe(collect($stored)->pluck('value')->all())
        ->and(collect($fields)->pluck('new')->all())->toBe(collect($stored)->pluck('new')->all());
});

it('edits a custom field on a batch item without touching sibling records', function (): void {
    Bus::fake();
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::batchTask($this->user, [
        ['title' => 'Task A', 'custom_fields' => [$field->code => $optionIds[0]]],
        ['title' => 'Task B', 'custom_fields' => [$field->code => $optionIds[0]]],
    ]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('focusItem', (string) $action->getKey(), 1)
        ->assertSet('cursor', 1)
        ->call('editField', $field->code)
        ->assertSet("data.custom_fields.{$field->code}", $optionIds[0])
        ->set("data.custom_fields.{$field->code}", $optionIds[1])
        ->call('saveField')
        ->assertSet('editingFieldCode', null)
        ->assertHasNoErrors();

    $records = array_values($action->fresh()->action_data['records']);
    expect($records[1]['custom_fields'][$field->code])->toBe($optionIds[1])
        ->and($records[0]['custom_fields'][$field->code])->toBe($optionIds[0]);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    expect(Task::query()->where('team_id', $this->team->getKey())->count())->toBe(0);
});

it('shows an edit affordance for an editable field and renders the inline editor when editing', function (): void {
    [$field, $optionIds] = ProposalCardFixture::seededTaskChoice($this->team);
    $action = ProposalCardFixture::task($this->user, ['title' => 'T', 'custom_fields' => [$field->code => $optionIds[0]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSeeHtml('editField')
        ->call('editField', 'title')
        ->assertSeeHtml('wire:click="saveField"')
        ->assertSeeHtml('wire:click="cancelField"');
});

it('rebuilds a company record (with account owner + custom field) without diverging from stored display', function (): void {
    $linkedin = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'linkedin')
        ->first();

    expect($linkedin)->not->toBeNull('seeded company linkedin field is required for this test');

    $tool = resolve(CreateCompanyTool::class);
    $tool->setConversationId(null);
    $tool->handle(new Request(['records' => [[
        'name' => 'Acme Corp',
        'account_owner_id' => (string) $this->user->getKey(),
        'custom_fields' => ['linkedin' => ['https://linkedin.com/company/acme']],
    ]]]));

    $action = PendingAction::query()->latest()->firstOrFail();

    $fields = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->instance()->currentRecordFields();

    $nameRow = collect($fields)->firstWhere('label', 'Name');
    expect($nameRow['code'] ?? null)->toBe('name');

    $ownerRow = collect($fields)->firstWhere('label', 'Account Owner');
    expect($ownerRow)->not->toBeNull()
        ->and($ownerRow['code'] ?? null)->toBe('account_owner_id')
        ->and($ownerRow['value'] ?? null)->toBe($this->user->name);

    $customRow = collect($fields)->firstWhere('code', 'linkedin');
    expect($customRow)->not->toBeNull();

    $stored = $action->display_data['fields'] ?? ($action->display_data['items'][0]['fields'] ?? []);

    expect(collect($fields)->pluck('label')->all())->toBe(collect($stored)->pluck('label')->all())
        ->and(collect($fields)->pluck('value')->all())->toBe(collect($stored)->pluck('value')->all())
        ->and(collect($fields)->pluck('new')->all())->toBe(collect($stored)->pluck('new')->all());
});

it('ignores skipItem while a field edit is open', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', 'name')
        ->call('skipItem', (string) $action->getKey(), 1)
        ->assertNotDispatched('proposal:resolved');

    expect($action->fresh()->result_data)->toBeNull();
});

it('shrinks the pagination count as records are skipped and hides it for the last one', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta', 'Gamma']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSee('1/3')
        ->call('skipItem', (string) $action->getKey(), 2)
        ->assertSee('1/2')
        ->assertDontSee('Gamma')
        ->call('skipItem', (string) $action->getKey(), 1)
        ->assertSee('Create')
        ->assertDontSee('1/1');
});
