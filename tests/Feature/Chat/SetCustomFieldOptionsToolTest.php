<?php

declare(strict_types=1);

use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CustomFieldOptionPlan;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\SetCustomFieldOptionsTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(SetCustomFieldOptionsTool::class, SetCustomFieldOptions::class, CustomFieldOptionPlan::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $this->lifecycle = setOptionsChoiceField($this->workspace, 'lifecycle', 'Lifecycle', 'select', ['Lead', 'Customer', 'Churned']);
    $this->segments = setOptionsChoiceField($this->workspace, 'segments', 'Segments', 'multi-select', ['Enterprise', 'SMB', 'Startup']);

    $this->convId = '019df900-7777-7000-8000-000000000004';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->owner->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

/**
 * @param  list<string>  $optionNames
 */
function setOptionsChoiceField(Workspace $workspace, string $code, string $name, string $type, array $optionNames, bool $encrypted = false): CustomField
{
    $field = CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => 'company',
        'code' => $code,
        'name' => $name,
        'type' => $type,
        'settings' => new CustomFieldSettingsData(encrypted: $encrypted),
    ]);

    foreach ($optionNames as $index => $optionName) {
        CustomFieldOption::query()->create([
            'tenant_id' => $workspace->getKey(),
            'custom_field_id' => $field->getKey(),
            'name' => $optionName,
            'sort_order' => $index + 1,
        ]);
    }

    return $field;
}

function setOptionsTaskStatus(Workspace $workspace): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->sole();
}

function setOptionsId(CustomField $field, string $name): string
{
    return (string) CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->where('name', $name)
        ->value('id');
}

/**
 * @return list<string>
 */
function setOptionsNames(CustomField $field): array
{
    return CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $field->getKey())
        ->orderBy('sort_order')
        ->get()
        ->map(fn (CustomFieldOption $option): string => (string) $option->name)
        ->all();
}

function setOptionsCompany(Workspace $workspace, CustomField $field, mixed $value): Company
{
    $company = Company::factory()->for($workspace)->create();
    $company->saveCustomFieldValue($field, $value);

    return $company;
}

function setOptionsStoredValue(Company $company, CustomField $field): mixed
{
    $row = DB::table('custom_field_values')
        ->where('entity_id', $company->getKey())
        ->where('custom_field_id', $field->getKey())
        ->first();

    return $field->type === 'multi-select'
        ? json_decode((string) $row->json_value, true)
        : $row->string_value;
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function setOptionsPropose(string $convId, array $records): array
{
    $tool = resolve(SetCustomFieldOptionsTool::class);
    $tool->setConversationId($convId);

    return json_decode($tool->handle(new Request(['records' => $records])), true);
}

function setOptionsPending(string $convId): PendingAction
{
    return PendingAction::query()->where('conversation_id', $convId)->sole();
}

it('renames an option in place, keeping its id and the records on it', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    $company = setOptionsCompany($this->workspace, $this->lifecycle, $leadId);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Prospect', 'current' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['type'])->toBe('pending_action')
        ->and($decoded['operation'])->toBe('update')
        ->and($decoded['data']['options'][0])->toBe(['id' => $leadId, 'name' => 'Prospect'])
        ->and($decoded['display']['fields'])->toContain(['label' => 'Renamed', 'old' => 'Lead', 'new' => 'Prospect']);

    $pending = setOptionsPending($this->convId);

    expect($pending->action_class)->toBe(SetCustomFieldOptions::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Update)
        ->and($pending->action_data['_model_class'])->toBe(CustomField::class)
        ->and($pending->display_data['summary'])->toBe('Change options of "Lifecycle"');

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($leadId)?->name)->toBe('Prospect')
        ->and(setOptionsStoredValue($company, $this->lifecycle))->toBe($leadId)
        ->and($pending->refresh()->status)->toBe(PendingActionStatus::Approved);
});

it('writes sort_order in the proposed order', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Churned'], ['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $sortOrders = CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $this->lifecycle->getKey())
        ->get()
        ->mapWithKeys(fn (CustomFieldOption $option): array => [(string) $option->name => $option->sort_order])
        ->sortKeys()
        ->all();

    expect($sortOrders)->toBe(['Churned' => 1, 'Customer' => 3, 'Lead' => 2]);
});

it('swaps two option names despite the unique name index', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    $customerId = setOptionsId($this->lifecycle, 'Customer');

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [
            ['name' => 'Customer', 'current' => 'Lead'],
            ['name' => 'Lead', 'current' => 'Customer'],
            ['name' => 'Churned'],
        ],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($leadId)?->name)->toBe('Customer')
        ->and(CustomFieldOption::query()->withoutGlobalScopes()->find($customerId)?->name)->toBe('Lead');
});

it('renames an option when only its letter case changes', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'LEAD'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($leadId)?->name)->toBe('LEAD')
        ->and(setOptionsNames($this->lifecycle))->toBe(['LEAD', 'Customer', 'Churned']);
});

it('refuses at proposal to remove an option records still use when no replacement is given', function (): void {
    setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Churned'));

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    expect($decoded['error'])->toContain('Records still use "Churned"')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses at approval a removal that records started using after the proposal', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    $pending = setOptionsPending($this->convId);
    setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Churned'));

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve($pending, $this->owner))
        ->toThrow(ValidationException::class, 'Records still use "Churned"');

    expect(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer', 'Churned']);
});

it('moves select records to the replacement, trashed ones included, and logs each move', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    $trashed = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    $live = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    $trashed->delete();

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer'], ['name' => 'Lost']],
        'replacements' => ['Churned' => 'Lost'],
    ]]);

    expect($decoded['data']['removed'])->toBe([$churnedId])
        ->and($decoded['display']['fields'])
        ->toContain(['label' => 'Added', 'value' => 'Lost'])
        ->toContain(['label' => 'Removed', 'value' => 'Churned (records move to Lost)']);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $lostId = setOptionsId($this->lifecycle, 'Lost');
    $moves = Activity::withoutGlobalScopes()
        ->where('event', 'custom_field_changes')
        ->where('subject_id', $live->getKey())
        ->get()
        ->map(fn (Activity $activity): array => [
            $activity->properties['custom_field_changes'][0]['old']['label'],
            $activity->properties['custom_field_changes'][0]['new']['label'],
        ])
        ->all();

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($churnedId))->toBeNull()
        ->and(setOptionsStoredValue($trashed, $this->lifecycle))->toBe($lostId)
        ->and(setOptionsStoredValue($live, $this->lifecycle))->toBe($lostId)
        ->and($moves)->toContain(['Churned', 'Lost'])
        ->and(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer', 'Lost']);
});

it('moves multi-select values to the replacement and drops a duplicate', function (): void {
    $enterpriseId = setOptionsId($this->segments, 'Enterprise');
    $smbId = setOptionsId($this->segments, 'SMB');
    $startupId = setOptionsId($this->segments, 'Startup');
    $both = setOptionsCompany($this->workspace, $this->segments, [$enterpriseId, $smbId]);
    $mixed = setOptionsCompany($this->workspace, $this->segments, [$smbId, $startupId]);

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'segments',
        'options' => [['name' => 'Enterprise'], ['name' => 'Startup']],
        'replacements' => ['SMB' => 'Enterprise'],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(setOptionsStoredValue($both, $this->segments))->toBe([$enterpriseId])
        ->and(setOptionsStoredValue($mixed, $this->segments))->toBe([$enterpriseId, $startupId])
        ->and(CustomFieldOption::query()->withoutGlobalScopes()->find($smbId))->toBeNull();
});

it('refuses to rename or remove the task Status option Done', function (): void {
    $renamed = setOptionsPropose($this->convId, [[
        'entity_type' => 'task',
        'code' => 'status',
        'options' => [['name' => 'To do'], ['name' => 'In progress'], ['name' => 'Completed', 'current' => 'Done']],
    ]]);

    $removed = setOptionsPropose($this->convId, [[
        'entity_type' => 'task',
        'code' => 'status',
        'options' => [['name' => 'To do'], ['name' => 'In progress']],
    ]]);

    expect($renamed['error'])->toContain('"Done" marks a task complete')
        ->and($removed['error'])->toContain('"Done" marks a task complete')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('adds a task status beside Done', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'task',
        'code' => 'status',
        'options' => [['name' => 'To do'], ['name' => 'In progress'], ['name' => 'In review'], ['name' => 'Done']],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(setOptionsNames(setOptionsTaskStatus($this->workspace)))->toBe(['To do', 'In progress', 'In review', 'Done']);
});

it('files two fields as one batch that approves per item', function (): void {
    $decoded = setOptionsPropose($this->convId, [
        [
            'entity_type' => 'company',
            'code' => 'lifecycle',
            'options' => [['name' => 'Prospect', 'current' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned']],
        ],
        [
            'entity_type' => 'company',
            'code' => 'segments',
            'options' => [['name' => 'Startup'], ['name' => 'SMB'], ['name' => 'Enterprise']],
        ],
    ]);

    $pending = setOptionsPending($this->convId);

    expect($decoded['data']['_batch'])->toBeTrue()
        ->and($pending->action_data['records'])->toHaveCount(2)
        ->and(array_column($pending->display_data['items'], 'summary'))
        ->toBe(['Change options of "Lifecycle"', 'Change options of "Segments"']);

    $service = resolve(PendingActionService::class);
    $service->approveItem($pending, $this->owner, 0);
    $service->approveItem($pending, $this->owner, 1);

    expect(setOptionsNames($this->lifecycle))->toBe(['Prospect', 'Customer', 'Churned'])
        ->and(setOptionsNames($this->segments))->toBe(['Startup', 'SMB', 'Enterprise'])
        ->and($pending->refresh()->status)->toBe(PendingActionStatus::Approved);
});

it('refuses a member with the role error and creates no proposal', function (): void {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    Auth::guard('web')->setUser($member);
    $this->actingAs($member);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned'], ['name' => 'Partner']],
    ]]);

    expect($decoded['error'])->toContain('workspace role does not allow that')
        ->and($decoded['error'])->toContain('Do not link to any page')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses approval when an option it keeps was deleted after the proposal', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Prospect', 'current' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    CustomFieldOption::query()->withoutGlobalScopes()->whereKey($leadId)->delete();

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'no longer exists on this field');

    expect(setOptionsNames($this->lifecycle))->toBe(['Customer', 'Churned']);
});

it('refuses a list that changes nothing', function (): void {
    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['error'])->toContain('Nothing to change')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses a field that has no options', function (): void {
    CustomField::factory()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'account_notes',
        'name' => 'Account notes',
        'type' => 'text',
    ]);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'account_notes',
        'options' => [['name' => 'Anything']],
    ]]);

    expect($decoded['error'])->toContain('has no options')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses to remove options from an encrypted field', function (): void {
    setOptionsChoiceField($this->workspace, 'tier', 'Tier', 'select', ['Gold', 'Silver'], encrypted: true);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'tier',
        'options' => [['name' => 'Gold']],
    ]]);

    expect($decoded['error'])->toContain('encrypted field')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses the same field twice in one call', function (): void {
    $record = [
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned'], ['name' => 'Partner']],
    ];

    $decoded = setOptionsPropose($this->convId, [$record, $record]);

    expect($decoded['error'])->toContain('already in this proposal')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses approval when an option was added after the proposal and deletes nothing', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Churned'], ['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $this->lifecycle->getKey(),
        'name' => 'Partner',
        'sort_order' => 4,
    ]);

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'The options of "Lifecycle" changed after this proposal. Ask for a fresh one.');

    expect(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer', 'Churned', 'Partner']);
});

it('refuses approval when a removed option was deleted after the proposal', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    CustomFieldOption::query()->withoutGlobalScopes()->whereKey(setOptionsId($this->lifecycle, 'Churned'))->delete();

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'changed after this proposal');
});

it('locks the field row before it writes any option', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Prospect', 'current' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    DB::enableQueryLog();
    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $queries = array_column(DB::getQueryLog(), 'query');
    $lock = array_search(true, array_map(fn (string $query): bool => str_contains($query, 'from "custom_fields"') && str_contains($query, 'for update'), $queries), true);
    $write = array_search(true, array_map(fn (string $query): bool => str_starts_with($query, 'update "custom_field_options"'), $queries), true);

    expect($lock)->toBeInt()
        ->and($write)->toBeInt()
        ->and($lock)->toBeLessThan($write);
});

it('refuses at proposal to move more records than the cap allows', function (): void {
    config(['chat.max_option_value_moves' => 2]);

    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => 'Lead'],
    ]]);

    expect($decoded['error'])->toContain('"Churned" is used by 3 records. This assistant moves at most 2 records in one approval.')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses at approval to move more records than the cap allows', function (): void {
    config(['chat.max_option_value_moves' => 2]);

    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => 'Lead'],
    ]]);

    $late = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'This assistant moves at most 2 records in one approval.');

    expect(setOptionsStoredValue($late, $this->lifecycle))->toBe($churnedId)
        ->and(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer', 'Churned']);
});
