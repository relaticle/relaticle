<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\RecordChipRenderer;
use Tests\Helpers\ProposalCardFixture;

mutates(ProposalCard::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

it('renders nothing when no active proposal id is set', function (): void {
    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->assertSet('pendingActionId', null)
        ->assertDontSee('Acme Corp');
});

it('loads and renders the active pending action summary', function (): void {
    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp'],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [['label' => 'Name', 'value' => 'Acme Corp']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('pendingActionId', $action->getKey())
        ->assertSeeHtml('data-proposal-record-chip')
        ->assertSeeHtml('data-record-type="company"')
        ->assertSee('Acme Corp');
});

it('leads a custom field proposal with the custom field icon and the field name', function (): void {
    $action = ProposalCardFixture::proposal($this->user,
        ['entity_type' => 'company', 'name' => 'Tier', 'type' => 'text'],
        ['title' => 'Create Custom Field', 'summary' => 'Create "Tier" (text) on company', 'fields' => [['label' => 'Name', 'value' => 'Tier']]],
    );
    $action->update(['entity_type' => 'custom_field', 'action_class' => CreateCustomField::class]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSeeHtml('data-proposal-record-chip')
        ->assertSeeHtml('data-record-type="custom_field"')
        ->assertSeeHtml(RecordChipRenderer::iconPath('custom_field'));
});

it('refuses a pending action from another tenant', function (): void {
    $other = User::factory()->withPersonalWorkspace()->create();
    $foreign = ProposalCardFixture::proposal($other, ['name' => 'Foreign'], ['title' => 'x', 'summary' => 'x', 'fields' => []]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $foreign->getKey(), context: 'conversation')
        ->assertSet('pendingActionId', null);
});

it('ignores set-active events targeted at a different chat context', function (): void {
    $action = ProposalCardFixture::proposal($this->user, ['name' => 'Acme'], ['title' => 't', 'summary' => 's', 'fields' => []]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'side-panel')
        ->assertSet('pendingActionId', null);
});

it('focuses a clicked batch record and snaps a resolved index to the first unresolved', function (): void {
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta', 'Gamma']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('cursor', 0)
        ->call('focusItem', (string) $action->getKey(), 2)->assertSet('cursor', 2)
        ->call('focusItem', (string) $action->getKey(), 1)->assertSet('cursor', 1);

    $action->update(['result_data' => ['items' => ['0' => ['status' => 'approved']]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('focusItem', (string) $action->getKey(), 0)
        ->assertSet('cursor', 1);
});

it('starts the cursor at the first unresolved record', function (): void {
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta', 'Gamma']);
    $action->update(['result_data' => ['items' => ['0' => ['status' => 'approved']]]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('cursor', 1);
});

it('does not surface an expired pending action', function (): void {
    $action = ProposalCardFixture::proposal($this->user, ['name' => 'Stale'], ['title' => 't', 'summary' => 's', 'fields' => []]);
    $action->update(['expires_at' => now()->subMinute()]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('pendingActionId', null);
});

it('tells the page when the proposal lapsed before the approve or discard click', function (string $method): void {
    $action = ProposalCardFixture::proposal($this->user, ['name' => 'Stale'], ['title' => 't', 'summary' => 's', 'fields' => []]);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation');

    $action->update(['expires_at' => now()->subMinute()]);

    $component->call($method)
        ->assertDispatched('proposal:lapsed', pendingActionId: $action->getKey(), context: 'conversation');

    expect(Company::query()->where('name', 'Stale')->exists())->toBeFalse();
})->with(['createCurrent', 'discardCurrent']);

it('creates every batch record from one footer click and collapses the dock', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved')
        ->assertSet('pendingActionId', null);

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->orderBy('name')->pluck('name')->all())
        ->toBe(['Alpha', 'Beta']);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('creates the single proposal record and collapses the dock', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp'],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [['label' => 'Name', 'value' => 'Acme Corp']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved')
        ->assertSet('pendingActionId', null);

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->where('name', 'Acme Corp')->exists())->toBeTrue();
    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('finalizes the batch on the last item and collapses the dock', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);
    $action->update(['result_data' => ['items' => ['0' => ['status' => 'approved', 'id' => 'x']], 'ids' => ['x']]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSet('cursor', 1)
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved')
        ->assertSet('pendingActionId', null);

    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('discards the whole batch from one footer click', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('discardCurrent')
        ->assertDispatched('proposal:resolved')
        ->assertSet('pendingActionId', null);

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Rejected);
});

it('finalizes after a skip plus create-all without dispatching a continuation', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('skipItem', (string) $action->getKey(), 1)
        ->call('createCurrent')
        ->assertSet('pendingActionId', null);
    expect($action->fresh()->status)->not->toBe(PendingActionStatus::Pending);

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->pluck('name')->all())
        ->toBe(['Alpha']);
});

it('emits proposal:resolve-failed and does not advance when the service rejects the resolution', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    // A non-allowlisted action_class makes the service reject the approve for a valid
    // (in-range, unresolved) item, exercising the catch -> resolve-failed path rather
    // than the stale-cursor guard.
    $action->update(['action_class' => 'stdClass']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('createCurrent') // cursor 0 is a valid unresolved item; the service throws
        ->assertDispatched('proposal:resolve-failed')
        ->assertNotDispatched('proposal:resolved')
        ->assertSet('pendingActionId', $action->getKey()); // not cleared

    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('drops a skipped item from the dock queue and cannot re-decide it', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta', 'Gamma']);

    $component = Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('skipItem', (string) $action->getKey(), 0);

    // Alpha left the queue: two records remain and the cursor sits on Beta.
    $component->assertViewHas('remainingCount', 2)
        ->assertSet('cursor', 1);

    // Skipping the already-decided index again is a no-op, not a re-run.
    $component->call('skipItem', (string) $action->getKey(), 0)
        ->assertSet('cursor', 1);

    $component->call('createCurrent');

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->orderBy('name')->pluck('name')->all())
        ->toBe(['Beta', 'Gamma']);
    expect($action->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('does nothing when createCurrent is called while a field edit is open', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('editField', 'name')
        ->call('createCurrent')
        ->assertNotDispatched('proposal:resolved');

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('routes the create-current shortcut to the whole batch for the matching context', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->dispatch('proposal:create-current', context: 'conversation')
        ->assertSet('pendingActionId', null);

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->orderBy('name')->pluck('name')->all())
        ->toBe(['Alpha', 'Beta']);
});

it('does nothing on the create-current shortcut when every row of a batch is left out', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleLeftOutRow', (string) $action->getKey(), 0)
        ->call('toggleLeftOutRow', (string) $action->getKey(), 1)
        ->dispatch('proposal:create-current', context: 'conversation')
        ->assertSet('pendingActionId', (string) $action->getKey());

    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('ignores the create-current shortcut for a different context', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->dispatch('proposal:create-current', context: 'side-panel')
        ->assertSet('cursor', 0);

    expect(Company::query()->where('workspace_id', $this->workspace->getKey())->count())->toBe(0);
});

it('offers no paging on a batch and counts the records it will create', function (): void {
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta', 'Gamma']);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertDontSee('1/3')
        ->assertDontSeeHtml('wire:click="nextItem"')
        ->assertDontSeeHtml('wire:click="prevItem"')
        ->assertViewHas('approvableRecordCount', 3);
});

it('leaves a batch row out and keeps it again, never a decided row', function (): void {
    $action = ProposalCardFixture::batchCompany($this->user, ['Alpha', 'Beta']);
    $stepId = (string) $action->getKey();

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleLeftOutRow', $stepId, 1)
        ->assertSet('leftOutRows', [$stepId => [1]])
        ->assertViewHas('approvableRecordCount', 1)
        ->call('toggleLeftOutRow', $stepId, 1)
        ->assertSet('leftOutRows', [$stepId => []])
        ->call('skipItem', $stepId, 0)
        ->call('toggleLeftOutRow', $stepId, 0)
        ->assertSet('leftOutRows', [$stepId => []]);
});

it('excludes an unchecked field from the write and records it in result_data', function (): void {
    Bus::fake();
    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp', 'account_owner_id' => $this->user->getKey()],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [
            ['label' => 'Name', 'code' => 'name', 'value' => 'Acme Corp'],
            ['label' => 'Account owner', 'code' => 'account_owner_id', 'value' => 'Someone'],
        ]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleField', 'account_owner_id')
        ->assertSet('excludedFields', ['account_owner_id'])
        ->call('createCurrent')
        ->assertDispatched('proposal:resolved');

    $company = Company::query()->where('workspace_id', $this->workspace->getKey())->where('name', 'Acme Corp')->first();

    expect($company)->not->toBeNull()
        ->and($company->account_owner_id)->toBeNull()
        ->and($action->fresh()->result_data['excluded'] ?? null)->toBe(['account_owner_id'])
        ->and($action->fresh()->action_data)->toHaveKey('account_owner_id');
});

it('never lets the title field be unchecked', function (): void {
    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp', 'account_owner_id' => $this->user->getKey()],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [
            ['label' => 'Name', 'code' => 'name', 'value' => 'Acme Corp'],
        ]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleField', 'name')
        ->assertSet('excludedFields', []);
});

it('offers no field exclusion on a batch, which leaves out whole rows instead', function (): void {
    Bus::fake();
    $records = [
        ['name' => 'Alpha', 'account_owner_id' => $this->user->getKey()],
        ['name' => 'Beta', 'account_owner_id' => $this->user->getKey()],
    ];
    $items = array_map(static fn (array $record): array => [
        'title' => $record['name'],
        'summary' => "Create company \"{$record['name']}\"",
        'fields' => [
            ['label' => 'Name', 'code' => 'name', 'value' => $record['name']],
            ['label' => 'Account owner', 'code' => 'account_owner_id', 'value' => 'Someone'],
        ],
    ], $records);
    $action = ProposalCardFixture::proposal(
        $this->user,
        ['_batch' => true, 'records' => $records],
        ['title' => 'Create Companies', 'summary' => 'Create 2 companies', 'items' => $items],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleField', 'account_owner_id')
        ->assertSet('excludedFields', [])
        ->call('createCurrent');

    $owners = Company::query()
        ->where('workspace_id', $this->workspace->getKey())
        ->pluck('account_owner_id', 'name')
        ->all();

    expect($owners['Alpha'])->not->toBeNull()
        ->and($owners['Beta'])->not->toBeNull();
});

it('unchecks and rechecks everything through the master toggle', function (): void {
    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp', 'account_owner_id' => $this->user->getKey()],
        ['title' => 'Create Company', 'summary' => 'Create company "Acme Corp"', 'fields' => [
            ['label' => 'Name', 'code' => 'name', 'value' => 'Acme Corp'],
            ['label' => 'Account owner', 'code' => 'account_owner_id', 'value' => 'Someone'],
        ]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleAllFields')
        ->assertSet('excludedFields', ['account_owner_id'])
        ->call('toggleAllFields')
        ->assertSet('excludedFields', []);
});

it('refuses to approve an update whose every change is unchecked', function (): void {
    Bus::fake();
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Stable Inc', 'account_owner_id' => null]);

    $action = PendingAction::query()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => 'App\\Actions\\Company\\UpdateCompany',
        'operation' => PendingActionOperation::Update,
        'entity_type' => 'company',
        'action_data' => ['_record_id' => (string) $company->getKey(), '_model_class' => Company::class, 'account_owner_id' => $this->user->getKey()],
        'display_data' => [
            'title' => 'Update Company',
            'summary' => 'Update company "Stable Inc"',
            'fields' => [['label' => 'Account owner', 'code' => 'account_owner_id', 'old' => 'Nobody', 'new' => 'Someone']],
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->call('toggleField', 'account_owner_id')
        ->call('createCurrent')
        ->assertNotDispatched('proposal:resolved');

    expect($action->fresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($company->fresh()->account_owner_id)->toBeNull();
});

it('shows the old value in the dock when a setting changes from zero', function (): void {
    $action = ProposalCardFixture::proposal($this->user,
        ['name' => 'Acme Corp'],
        ['title' => 'Update Custom Field', 'summary' => 'Update custom field "Amount"', 'fields' => [['label' => 'Decimal Places', 'old' => '0', 'new' => '2']]],
    );

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSeeHtml('dark:decoration-gray-600">0</span>');
});

it('renders a single (non-batch) proposal without a stepper', function (): void {
    $action = ProposalCardFixture::proposal($this->user, ['name' => 'Solo Inc'], ['title' => 'Create Company', 'summary' => 'Solo Inc', 'fields' => [['label' => 'Name', 'value' => 'Solo Inc']]]);

    Livewire::test(ProposalCard::class, ['context' => 'conversation'])
        ->dispatch('proposal:set-active', id: $action->getKey(), context: 'conversation')
        ->assertSee('Solo Inc')
        ->assertSee('Create');
});
