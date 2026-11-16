<?php

declare(strict_types=1);

use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Enums\OptionColor;
use App\Exceptions\OptionsChangedException;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CustomFieldOptionPlan;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\SetCustomFieldOptionsTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\Activitylog\Support\CauserResolver;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        ->and(array_keys($decoded))->toBe(['type', 'pending_action_id', 'turn_id', 'action', 'entity_type', 'operation', 'data', 'display', 'summary', 'meta'])
        ->and($decoded['data']['options'][0])->toBe(['id' => $leadId, 'name' => 'Prospect', 'was' => 'Lead'])
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

it('removes an option only deleted records hold and clears their value', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    $trashed = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    $trashed->delete();

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    expect($decoded)->not->toHaveKey('error')
        ->and($decoded['display']['fields'])->toContain(['label' => 'Removed', 'value' => 'Churned']);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($churnedId))->toBeNull()
        ->and(setOptionsStoredValue($trashed, $this->lifecycle))->toBeNull()
        ->and(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer']);
});

it('removes a multi-select option only deleted records hold and keeps their other options', function (): void {
    $trashed = setOptionsCompany($this->workspace, $this->segments, [setOptionsId($this->segments, 'Enterprise'), setOptionsId($this->segments, 'SMB')]);
    $trashed->delete();

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'segments',
        'options' => [['name' => 'SMB'], ['name' => 'Startup']],
    ]]);

    expect($decoded)->not->toHaveKey('error');

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(setOptionsStoredValue($trashed, $this->segments))->toBe([setOptionsId($this->segments, 'SMB')]);
});

it('refuses at approval when a live record took an option only deleted records held at proposal', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId)->delete();

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
    ]]);

    $live = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'Records still use "Churned"');

    expect(setOptionsStoredValue($live, $this->lifecycle))->toBe($churnedId)
        ->and(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer', 'Churned']);
});

it('still refuses a removal without a replacement when a live record shares the option with deleted ones', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId)->delete();
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);

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

    expect($decoded['error'])->toContain('encrypted attribute')
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
    $lock = array_search(true, array_map(fn (string $query): bool => str_contains($query, 'from "custom_fields"') && str_contains($query, 'for no key update'), $queries), true);
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

it('moves a value whose record no longer exists', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    $orphaned = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    DB::table('companies')->where('id', $orphaned->getKey())->delete();

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer'], ['name' => 'Lost']],
        'replacements' => ['Churned' => 'Lost'],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(setOptionsStoredValue($orphaned, $this->lifecycle))->toBe(setOptionsId($this->lifecycle, 'Lost'))
        ->and(setOptionsNames($this->lifecycle))->toBe(['Lead', 'Customer', 'Lost']);
});

it('stores a renamed option of a deactivated encrypted field as ciphertext', function (): void {
    $tier = setOptionsChoiceField($this->workspace, 'tier', 'Tier', 'select', ['Gold', 'Silver'], encrypted: true);
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'tier',
        'options' => [['name' => 'Platinum', 'current' => 'Gold'], ['name' => 'Silver'], ['name' => 'Bronze']],
    ]]);

    CustomField::query()->withoutGlobalScopes()->whereKey($tier->getKey())->update(['active' => false]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $stored = DB::table('custom_field_options')->where('custom_field_id', $tier->getKey())->orderBy('sort_order')->pluck('name');

    expect($stored->all())->not->toContain('Platinum', 'Bronze')
        ->and($stored->map(fn (string $name): string => decrypt($name, false))->all())->toBe(['Platinum', 'Silver', 'Bronze']);
});

it('refuses approval when an option it keeps was renamed after the proposal', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Customer'], ['name' => 'Lead'], ['name' => 'Churned']],
    ]]);

    CustomFieldOption::query()->withoutGlobalScopes()->whereKey($leadId)->update(['name' => 'Prospect']);

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'changed after this proposal');

    expect(setOptionsNames($this->lifecycle))->toBe(['Prospect', 'Customer', 'Churned']);
});

it('sets an option color from the palette and turns option colors on', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead', 'color' => OptionColor::Blue->value], ['name' => 'Customer', 'color' => OptionColor::Green->value], ['name' => 'Churned']],
    ]]);

    expect($decoded['data']['options'][0])->toBe(['id' => $leadId, 'name' => 'Lead', 'was' => 'Lead', 'color' => OptionColor::Blue->value])
        ->and($decoded['data']['options'][2])->not->toHaveKey('color')
        ->and($decoded['display']['fields'])->toContain(['label' => 'Color', 'value' => 'Lead: Blue']);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $colors = CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $this->lifecycle->getKey())->get()->mapWithKeys(fn (CustomFieldOption $option): array => [(string) $option->name => $option->settings->color]);

    expect($colors->all())->toEqual(['Lead' => OptionColor::Blue->value, 'Customer' => OptionColor::Green->value, 'Churned' => null])
        ->and($this->lifecycle->refresh()->settings->enable_option_colors)->toBeTrue();
});

it('adds an option with a color', function (): void {
    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned'], ['name' => 'Partner', 'color' => OptionColor::Purple->value]],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->where('name', 'Partner')->sole()->settings->color)->toBe(OptionColor::Purple->value);
});

it('keeps the current color when an option carries none', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->update(['settings' => ['color' => OptionColor::Red->value]]);

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Prospect', 'current' => 'Lead'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->settings->color)->toBe(OptionColor::Red->value);
});

it('turns confetti on for a stage, shows it in the proposal and keeps its color', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->update(['settings' => ['color' => OptionColor::Red->value]]);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead', 'celebrate' => true], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['data']['options'][0])->toBe(['id' => $leadId, 'name' => 'Lead', 'was' => 'Lead', 'celebrate' => true])
        ->and($decoded['display']['fields'])->toContain(['label' => 'Confetti', 'value' => 'Lead: On']);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $settings = CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->settings;

    expect($settings->celebrate)->toBeTrue()
        ->and($settings->color)->toBe(OptionColor::Red->value);
});

it('keeps confetti when the proposal recolors another option', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->update(['settings' => ['color' => OptionColor::Red->value, 'celebrate' => true]]);

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer', 'color' => OptionColor::Blue->value], ['name' => 'Churned']],
    ]]);
    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->settings->celebrate)->toBeTrue();
});

it('turns confetti off and recolors the same option in one proposal', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->update(['settings' => ['color' => OptionColor::Red->value, 'celebrate' => true]]);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead', 'color' => OptionColor::Green->value, 'celebrate' => false], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['display']['fields'])->toContain(['label' => 'Confetti', 'value' => 'Lead: Off']);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $settings = CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->settings;

    expect($settings->celebrate)->toBeFalse()
        ->and($settings->color)->toBe(OptionColor::Green->value);
});

it('refuses a proposal that changes nothing when confetti already has the asked state', function (): void {
    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead', 'celebrate' => false], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['error'])->toContain('Nothing to change');
});

it('refuses a color outside the palette', function (): void {
    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Prospect', 'current' => 'Lead', 'color' => 'teal'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['error'])->toContain('color');
    expect(PendingAction::query()->count())->toBe(0);
});

it('lets the action set a color through the same validation as the proposal', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    $options = collect(['Lead', 'Customer', 'Churned'])->map(fn (string $name): array => ['id' => setOptionsId($this->lifecycle, $name), 'name' => $name, 'was' => $name])->all();
    $options[0]['color'] = OptionColor::Orange->value;

    resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, ['options' => $options, 'removed' => []]);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->settings->color)->toBe(OptionColor::Orange->value);

    $options[0]['color'] = '#000000';

    expect(fn (): CustomField => resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, ['options' => $options, 'removed' => []]))->toThrow(ValidationException::class);
});

it('refuses a palette name as a color', function (): void {
    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead', 'color' => 'Blue'], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded['error'])->toContain('color');
});

it('proposes a color the option already stores when option colors are off', function (): void {
    $leadId = setOptionsId($this->lifecycle, 'Lead');
    CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($leadId)->update(['settings' => ['color' => OptionColor::Blue->value]]);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead', 'color' => OptionColor::Blue->value], ['name' => 'Customer'], ['name' => 'Churned']],
    ]]);

    expect($decoded)->not->toHaveKey('error');

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect($this->lifecycle->refresh()->settings->enable_option_colors)->toBeTrue();
});

it('clears the values of a removed select option when the replacement is null and states the count on the card', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    $first = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    $second = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    $kept = setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Lead'));

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => null],
    ]]);

    expect($decoded)->not->toHaveKey('error')
        ->and($decoded['display']['fields'])->toContain(['label' => 'Removed', 'value' => 'Churned (2 records lose their value)']);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    $logged = Activity::withoutGlobalScopes()
        ->where('event', 'custom_field_changes')
        ->where('subject_id', $first->getKey())
        ->get()
        ->map(fn (Activity $activity): string => $activity->properties['custom_field_changes'][0]['old']['label'])
        ->all();

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($churnedId))->toBeNull()
        ->and(setOptionsStoredValue($first, $this->lifecycle))->toBeNull()
        ->and(setOptionsStoredValue($second, $this->lifecycle))->toBeNull()
        ->and(setOptionsStoredValue($kept, $this->lifecycle))->toBe(setOptionsId($this->lifecycle, 'Lead'))
        ->and($logged)->toContain('Churned');
});

it('removes only the cleared option from multi-select values', function (): void {
    $enterpriseId = setOptionsId($this->segments, 'Enterprise');
    $smbId = setOptionsId($this->segments, 'SMB');
    $startupId = setOptionsId($this->segments, 'Startup');
    $only = setOptionsCompany($this->workspace, $this->segments, [$smbId]);
    $mixed = setOptionsCompany($this->workspace, $this->segments, [$enterpriseId, $smbId, $startupId]);

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'segments',
        'options' => [['name' => 'Enterprise'], ['name' => 'Startup']],
        'replacements' => ['SMB' => null],
    ]]);

    resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner);

    expect(setOptionsStoredValue($only, $this->segments))->toBe([])
        ->and(setOptionsStoredValue($mixed, $this->segments))->toBe([$enterpriseId, $startupId])
        ->and(CustomFieldOption::query()->withoutGlobalScopes()->find($smbId))->toBeNull();
});

it('shows the plain option name on the card when a cleared option holds no records', function (): void {
    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => null],
    ]]);

    expect($decoded['display']['fields'])->toContain(['label' => 'Removed', 'value' => 'Churned']);
});

it('still refuses a removal of a used option that carries neither a replacement nor the clear marker', function (): void {
    setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Churned'));

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => [],
    ]]);

    expect($decoded['error'])->toContain('Records still use "Churned"');
});

it('clears values through the action and refuses a member', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    $company = setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    $options = collect(['Lead', 'Customer'])->map(fn (string $name): array => ['id' => setOptionsId($this->lifecycle, $name), 'name' => $name, 'was' => $name])->all();
    $data = ['options' => $options, 'replacements' => [$churnedId => null], 'removed' => [$churnedId]];

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    expect(fn (): CustomField => resolve(SetCustomFieldOptions::class)->execute($member, $this->lifecycle, $data))->toThrow(HttpException::class);
    expect(setOptionsStoredValue($company, $this->lifecycle))->toBe($churnedId);

    resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, $data);

    expect(setOptionsStoredValue($company, $this->lifecycle))->toBeNull();
});

it('names the clear marker in the assistant instructions', function (): void {
    expect(resolve(CrmAssistant::class)->staticInstructions())->toContain('pass where they move in `replacements`, or null to clear their value');
});

it('counts only live records in the clear count on the card', function (): void {
    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId)->delete();

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => null],
    ]]);

    expect($decoded['display']['fields'])->toContain(['label' => 'Removed', 'value' => 'Churned (1 record loses its value)']);
});

it('shows no count when only trashed records hold the cleared option', function (): void {
    setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Churned'))->delete();

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => null],
    ]]);

    expect($decoded['display']['fields'])->toContain(['label' => 'Removed', 'value' => 'Churned']);
});

it('says records lose the option, not their value, on a multi-select field', function (): void {
    $smbId = setOptionsId($this->segments, 'SMB');
    setOptionsCompany($this->workspace, $this->segments, [$smbId]);
    setOptionsCompany($this->workspace, $this->segments, [$smbId, setOptionsId($this->segments, 'Startup')]);

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'segments',
        'options' => [['name' => 'Enterprise'], ['name' => 'Startup']],
        'replacements' => ['SMB' => null],
    ]]);

    expect($decoded['display']['fields'])->toContain(['label' => 'Removed', 'value' => 'SMB (2 records lose this option)']);
});

it('refuses at proposal to clear more records than the cap allows, trashed ones counted', function (): void {
    config(['chat.max_option_value_moves' => 2]);

    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId)->delete();

    $decoded = setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => null],
    ]]);

    expect($decoded['error'])->toContain('"Churned" would be cleared on 3 records. This assistant clears or moves at most 2 records in one approval.')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses at approval to clear more records than the cap allows', function (): void {
    config(['chat.max_option_value_moves' => 2]);

    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    setOptionsCompany($this->workspace, $this->lifecycle, $churnedId);

    setOptionsPropose($this->convId, [[
        'entity_type' => 'company',
        'code' => 'lifecycle',
        'options' => [['name' => 'Lead'], ['name' => 'Customer']],
        'replacements' => ['Churned' => null],
    ]]);

    $late = [setOptionsCompany($this->workspace, $this->lifecycle, $churnedId), setOptionsCompany($this->workspace, $this->lifecycle, $churnedId)];

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve(setOptionsPending($this->convId), $this->owner))
        ->toThrow(ValidationException::class, 'This assistant clears or moves at most 2 records in one approval.');

    expect(setOptionsStoredValue($late[0], $this->lifecycle))->toBe($churnedId);
});

it('lets a caller that opts out clear more records than the cap', function (): void {
    config(['chat.max_option_value_moves' => 1]);

    $churnedId = setOptionsId($this->lifecycle, 'Churned');
    $companies = [setOptionsCompany($this->workspace, $this->lifecycle, $churnedId), setOptionsCompany($this->workspace, $this->lifecycle, $churnedId)];
    $options = collect(['Lead', 'Customer'])->map(fn (string $name): array => ['id' => setOptionsId($this->lifecycle, $name), 'name' => $name, 'was' => $name])->all();
    $data = ['options' => $options, 'replacements' => [$churnedId => null], 'removed' => [$churnedId]];

    expect(fn (): CustomField => resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, $data))->toThrow(ValidationException::class);

    resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, $data, capsClears: false);

    expect(setOptionsStoredValue($companies[0], $this->lifecycle))->toBeNull();
});

it('raises a distinct exception when the options changed under the caller', function (): void {
    $options = collect(['Lead', 'Customer', 'Churned'])->map(fn (string $name): array => ['id' => setOptionsId($this->lifecycle, $name), 'name' => $name, 'was' => $name])->all();
    $options[0]['was'] = 'Other';
    $options[0]['name'] = 'Renamed';

    expect(fn (): CustomField => resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, ['options' => $options, 'removed' => []]))
        ->toThrow(OptionsChangedException::class);
});

function setOptionsActivityRow(Company $company): array
{
    return Arr::except(
        Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $company->getKey())->orderByDesc('id')->firstOrFail()->toArray(),
        ['id', 'subject_id', 'created_at', 'updated_at'],
    );
}

function setOptionsClearTheOldWay(Company $company, CustomField $field): void
{
    $column = $field->type === 'multi-select' ? 'json_value' : 'string_value';
    $removing = $field->type === 'multi-select' ? 'SMB' : 'Churned';
    $value = CustomFieldValue::query()->where('entity_id', $company->getKey())->where('custom_field_id', $field->getKey())->sole();
    $value->setAttribute($column, $column === 'json_value'
        ? collect($value->json_value)->reject(fn (string $id): bool => $id === setOptionsId($field, $removing))->values()
        : null);
    $value->save();
}

function setOptionsClearWithNull(object $test, CustomField $field, array $keep, string $removing): void
{
    $options = collect($keep)->map(fn (string $name): array => ['id' => setOptionsId($field, $name), 'name' => $name, 'was' => $name])->all();
    $id = setOptionsId($field, $removing);

    resolve(SetCustomFieldOptions::class)->execute($test->owner, $field, ['options' => $options, 'replacements' => [$id => null], 'removed' => [$id]], capsClears: false);
}

it('writes the same activity row in bulk as the per-record save wrote, for a single choice field', function (): void {
    $churned = setOptionsId($this->lifecycle, 'Churned');
    $twin = setOptionsCompany($this->workspace, $this->lifecycle, $churned);
    setOptionsClearTheOldWay($twin, $this->lifecycle);
    $expected = setOptionsActivityRow($twin);

    $live = setOptionsCompany($this->workspace, $this->lifecycle, $churned);
    $trashed = setOptionsCompany($this->workspace, $this->lifecycle, $churned);
    $trashed->delete();
    $untouched = setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Lead'));
    $otherField = setOptionsCompany($this->workspace, $this->segments, [setOptionsId($this->segments, 'SMB')]);
    $untouchedLogs = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->whereIn('subject_id', [$untouched->getKey(), $otherField->getKey()])->count();

    $intruder = User::factory()->withPersonalWorkspace()->create();
    $foreignField = setOptionsChoiceField($intruder->currentWorkspace, 'lifecycle', 'Lifecycle', 'select', ['Lead', 'Customer', 'Churned']);
    TenantContextService::setTenantId($intruder->currentWorkspace->getKey());
    $foreign = setOptionsCompany($intruder->currentWorkspace, $foreignField, setOptionsId($foreignField, 'Churned'));
    TenantContextService::setTenantId($this->workspace->getKey());
    $foreignLogs = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $foreign->getKey())->count();

    setOptionsClearWithNull($this, $this->lifecycle, ['Lead', 'Customer'], 'Churned');

    expect($expected['workspace_id'])->toBe($this->workspace->getKey())
        ->and(setOptionsActivityRow($live))->toEqual($expected)
        ->and(setOptionsActivityRow($trashed))->toEqual($expected)
        ->and(setOptionsStoredValue($live, $this->lifecycle))->toBeNull()
        ->and(setOptionsStoredValue($trashed, $this->lifecycle))->toBeNull()
        ->and(setOptionsStoredValue($untouched, $this->lifecycle))->toBe(setOptionsId($this->lifecycle, 'Lead'))
        ->and(setOptionsStoredValue($otherField, $this->segments))->toBe([setOptionsId($this->segments, 'SMB')])
        ->and(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->whereIn('subject_id', [$untouched->getKey(), $otherField->getKey()])->count())->toBe($untouchedLogs)
        ->and(setOptionsStoredValue($foreign, $foreignField))->toBe(setOptionsId($foreignField, 'Churned'))
        ->and(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $foreign->getKey())->count())->toBe($foreignLogs);
});

it('writes the same activity row in bulk as the per-record save wrote, for a multi choice field', function (): void {
    [$enterprise, $smb, $startup] = array_map(fn (string $name): string => setOptionsId($this->segments, $name), ['Enterprise', 'SMB', 'Startup']);
    $twin = setOptionsCompany($this->workspace, $this->segments, [$enterprise, $smb]);
    setOptionsClearTheOldWay($twin, $this->segments);
    $expected = setOptionsActivityRow($twin);

    $mixed = setOptionsCompany($this->workspace, $this->segments, [$enterprise, $smb]);
    $only = setOptionsCompany($this->workspace, $this->segments, [$smb]);
    $trashed = setOptionsCompany($this->workspace, $this->segments, [$smb, $startup]);
    $trashed->delete();
    $other = setOptionsCompany($this->workspace, $this->segments, [$enterprise, $startup]);
    $otherLogs = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $other->getKey())->count();

    setOptionsClearWithNull($this, $this->segments, ['Enterprise', 'Startup'], 'SMB');

    $onlyRow = setOptionsActivityRow($only);

    expect(setOptionsActivityRow($mixed))->toEqual($expected)
        ->and($onlyRow['properties']['custom_field_changes'][0]['new'])->toBe(['value' => null, 'label' => '—'])
        ->and(setOptionsStoredValue($mixed, $this->segments))->toBe([$enterprise])
        ->and(setOptionsStoredValue($only, $this->segments))->toBe([])
        ->and(setOptionsStoredValue($trashed, $this->segments))->toBe([$startup])
        ->and(setOptionsStoredValue($other, $this->segments))->toBe([$enterprise, $startup])
        ->and(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $other->getKey())->count())->toBe($otherLogs);
});

it('clears a multi-select value stored as a bare json string and logs it', function (): void {
    $smb = setOptionsId($this->segments, 'SMB');
    $scalar = setOptionsCompany($this->workspace, $this->segments, [$smb]);
    $array = setOptionsCompany($this->workspace, $this->segments, [$smb, setOptionsId($this->segments, 'Startup')]);
    DB::table('custom_field_values')->where('entity_id', $scalar->getKey())->where('custom_field_id', $this->segments->getKey())->update(['json_value' => json_encode($smb)]);

    setOptionsClearWithNull($this, $this->segments, ['Enterprise', 'Startup'], 'SMB');

    expect(setOptionsStoredValue($scalar, $this->segments))->toBe([])
        ->and(setOptionsStoredValue($array, $this->segments))->toBe([setOptionsId($this->segments, 'Startup')])
        ->and(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $scalar->getKey())->count())->toBe(2);
});

it('leaves a card that moved out of the stage after the chunk was read untouched and unlogged', function (string $kind): void {
    $multi = $kind === 'multi';
    $field = $multi ? $this->segments : $this->lifecycle;
    $removing = setOptionsId($field, $multi ? 'SMB' : 'Churned');
    $staying = setOptionsId($field, $multi ? 'Startup' : 'Lead');
    $make = fn (): Company => setOptionsCompany($this->workspace, $field, $multi ? [$removing] : $removing);
    $cleared = $make();
    $moved = $make();
    $logsBefore = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $moved->getKey())->count();
    $armed = true;

    DB::listen(function ($query) use (&$armed, $moved, $field, $multi, $staying): void {
        if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'custom_field_values') && str_contains($query->sql, 'order by')) {
            $armed = false;
            DB::table('custom_field_values')->where('entity_id', $moved->getKey())->where('custom_field_id', $field->getKey())->update([$multi ? 'json_value' : 'string_value' => $multi ? json_encode([$staying]) : $staying]);
        }
    });

    setOptionsClearWithNull($this, $field, $multi ? ['Enterprise', 'Startup'] : ['Lead', 'Customer'], $multi ? 'SMB' : 'Churned');

    expect(setOptionsStoredValue($moved, $field))->toBe($multi ? [$staying] : $staying)
        ->and(setOptionsStoredValue($cleared, $field))->toBe($multi ? [] : null)
        ->and(Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $moved->getKey())->count())->toBe($logsBefore);
})->with(['single', 'multi']);

it('writes no activity rows while the activity log is disabled and still clears the values', function (): void {
    $company = setOptionsCompany($this->workspace, $this->lifecycle, setOptionsId($this->lifecycle, 'Churned'));
    $logs = fn (): int => Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $company->getKey())->count();
    $before = $logs();

    activity()->withoutLogging(fn () => setOptionsClearWithNull($this, $this->lifecycle, ['Lead', 'Customer'], 'Churned'));

    expect(setOptionsStoredValue($company, $this->lifecycle))->toBeNull()
        ->and($logs())->toBe($before);
});

it('takes the causer from the causer resolver when nobody is signed in', function (): void {
    $churned = setOptionsId($this->lifecycle, 'Churned');
    $company = setOptionsCompany($this->workspace, $this->lifecycle, $churned);
    Auth::guard('web')->forgetUser();

    resolve(CauserResolver::class)->withCauser($this->owner, fn () => setOptionsClearWithNull($this, $this->lifecycle, ['Lead', 'Customer'], 'Churned'));

    $row = Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->where('subject_id', $company->getKey())->orderByDesc('id')->first();

    expect($row->causer_id)->toBe((string) $this->owner->getKey());
});

it('raises the changed exception when a kept option vanished before the write', function (): void {
    $options = collect(['Lead', 'Customer'])->map(fn (string $name): array => ['id' => setOptionsId($this->lifecycle, $name), 'name' => $name, 'was' => $name])->all();
    $options[] = ['id' => '01jzzzzzzzzzzzzzzzzzzzzzzz', 'name' => 'Ghost', 'was' => 'Ghost'];

    expect(fn (): CustomField => resolve(SetCustomFieldOptions::class)->execute($this->owner, $this->lifecycle, ['options' => $options, 'removed' => []]))
        ->toThrow(OptionsChangedException::class);
});
