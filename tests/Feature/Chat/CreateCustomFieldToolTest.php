<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\CreateCustomFieldTool;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(CreateCustomFieldTool::class, CreateCustomField::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $this->convId = '019df900-5555-7000-8000-000000000001';
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

function makeCreateFieldTool(string $convId): CreateCustomFieldTool
{
    $tool = resolve(CreateCustomFieldTool::class);
    $tool->setConversationId($convId);

    return $tool;
}

/**
 * @param  array<string, mixed>  ...$records
 * @return array<string, mixed>
 */
function proposeCustomFields(string $convId, array ...$records): array
{
    return (array) json_decode(makeCreateFieldTool($convId)->handle(new Request(['records' => $records])), true);
}

it('creates a pending proposal for a select field with options', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High'], ['name' => 'Low']],
    ]);

    expect($decoded['type'])->toBe('pending_action')
        ->and($decoded['operation'])->toBe('create')
        ->and($decoded['entity_type'])->toBe('custom_field')
        ->and($decoded['meta']['agent_should_stop'])->toBeTrue()
        ->and(array_keys($decoded))->toBe(['type', 'pending_action_id', 'turn_id', 'action', 'entity_type', 'operation', 'data', 'display', 'meta']);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($pending->action_class)->toBe(CreateCustomField::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Create)
        ->and($pending->entity_type)->toBe(ProposalEntity::CustomField)
        ->and($pending->action_data['name'])->toBe('Priority')
        ->and($pending->action_data['type'])->toBe('select')
        ->and($pending->action_data['entity_type'])->toBe('company')
        ->and($pending->action_data['options'])->toHaveCount(2)
        ->and($pending->status)->toBe(PendingActionStatus::Pending);
});

it('executes the approved proposal and creates the field + options in the database', function (): void {
    proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High'], ['name' => 'Low']],
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    $field = CustomField::query()->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->where('name', 'Priority')
        ->first();

    expect($field)->not->toBeNull()
        ->and($field->type)->toBe('select')
        ->and($field->active)->toBeTrue()
        ->and($field->system_defined)->toBeFalse();

    TenantContextService::setTenantId($this->workspace->getKey());
    $optionNames = CustomFieldOption::query()
        ->where('custom_field_id', $field->getKey())
        ->pluck('name')
        ->sort()
        ->values()
        ->toArray();

    expect($optionNames)->toBe(['High', 'Low']);
});

it('refuses a member with the role error and creates no proposal', function (): void {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    Auth::guard('web')->setUser($member);
    $this->actingAs($member);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High']],
    ]);

    expect($decoded['error'])->toContain('workspace role does not allow that')
        ->and($decoded['error'])->toContain('Do not link to any page')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('lets an admin propose a field that lands on approval', function (): void {
    $admin = User::factory()->create();
    $admin->workspaces()->attach($this->workspace, ['role' => 'admin']);
    $admin->switchWorkspace($this->workspace);

    Auth::guard('web')->setUser($admin);
    $this->actingAs($admin);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Region',
        'type' => 'text',
    ]);

    expect($decoded['type'])->toBe('pending_action');

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    resolve(PendingActionService::class)->approve($pending, $admin);

    expect(CustomField::query()->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->where('name', 'Region')
        ->exists())->toBeTrue();
});

it('returns error for a non-allowlisted field type', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Attachment',
        'type' => 'file-upload',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses to propose a domain field', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Second domains',
        'type' => 'domain',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects when over the max_custom_fields_per_entity cap', function (): void {
    config(['chat.max_custom_fields_per_entity' => 2]);

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

    CustomField::factory()->count(2)->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'company',
    ]);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'One More',
        'type' => 'text',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('requires options for choice types', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Status',
        'type' => 'select',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('creates a text field without options successfully', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Website',
        'type' => 'text',
    ]);

    expect($decoded['type'])->toBe('pending_action')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(1);
});

it('rejects a duplicate field name at proposal time', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'people',
        'name' => 'Age',
        'type' => 'number',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('already exists')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects a duplicate field name even when the existing field is deactivated', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
        'active' => false,
    ]);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'people',
        'name' => 'Age',
        'type' => 'number',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('allows the same field name on a different entity type', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Age',
        'type' => 'number',
    ]);

    expect($decoded['type'])->toBe('pending_action')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(1);
});

it('rejects approval when a field with the same name appeared after the proposal', function (): void {
    proposeCustomFields($this->convId, [
        'entity_type' => 'people',
        'name' => 'Age',
        'type' => 'number',
    ]);

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->owner))
        ->toThrow(ValidationException::class, 'already exists');

    $ageFields = CustomField::query()
        ->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('name', 'Age')
        ->count();

    expect($ageFields)->toBe(1)
        ->and($pending->refresh()->status)->toBe(PendingActionStatus::Pending);
});

it('rejects a duplicate explicit code at proposal time', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'people',
        'name' => 'Years',
        'type' => 'number',
        'code' => 'age',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('already exists')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects approval with a friendly message when the explicit code was taken after the proposal', function (): void {
    proposeCustomFields($this->convId, [
        'entity_type' => 'people',
        'name' => 'Reference',
        'type' => 'text',
        'code' => 'ref_no',
    ]);

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Ref Number',
        'code' => 'ref_no',
        'type' => 'text',
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->owner))
        ->toThrow(ValidationException::class, 'already exists');
});

it('rejects an empty field name', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => '   ',
        'type' => 'text',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects a field name longer than 50 characters', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => str_repeat('a', 51),
        'type' => 'text',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects a code with invalid characters', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Reference',
        'type' => 'text',
        'code' => 'bad code!',
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects duplicate option names within the options array', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High'], ['name' => 'High']],
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects empty option names', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => ''], ['name' => 'Low']],
    ]);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects a field name that differs from an existing one only by case', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $decoded = json_decode((new CreateCustomFieldTool)->handle(new Request(['records' => [[
        'entity_type' => 'people',
        'name' => 'age',
        'type' => 'number',
    ]]])), true);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('already exists')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects a recased duplicate at approval time, not just at proposal time', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

    expect(fn () => app(CreateCustomField::class)->execute($this->owner, [
        'entity_type' => 'people',
        'name' => 'Renewal Date',
        'type' => 'date',
    ]))->not->toThrow(ValidationException::class);

    expect(fn () => app(CreateCustomField::class)->execute($this->owner, [
        'entity_type' => 'people',
        'name' => 'RENEWAL DATE',
        'type' => 'date',
    ]))->toThrow(ValidationException::class, 'already exists');

    expect(CustomField::query()
        ->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where($tenantKey, $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->whereRaw('lower(name) = ?', ['renewal date'])
        ->count())->toBe(1);
});

it('still allows a name that merely shares a prefix with an existing field', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $decoded = json_decode((new CreateCustomFieldTool)->handle(new Request(['records' => [[
        'entity_type' => 'people',
        'name' => 'Age Bracket',
        'type' => 'text',
    ]]])), true);

    expect($decoded)->not->toHaveKey('error');
});

it('proposes fields across two entities as one batch approved item by item', function (): void {
    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Priority', 'type' => 'select', 'options' => [['name' => 'High'], ['name' => 'Low']]],
        ['entity_type' => 'company', 'name' => 'Region', 'type' => 'text'],
        ['entity_type' => 'people', 'name' => 'Tier', 'type' => 'select', 'options' => [['name' => 'Gold'], ['name' => 'Silver']]],
    );

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->sole();

    expect($decoded['data']['_batch'])->toBeTrue()
        ->and($decoded)->not->toHaveKey('skipped_records')
        ->and($pending->action_data['records'])->toHaveCount(3)
        ->and($pending->display_data['summary'])->toBe('Create 3 custom fields')
        ->and($pending->display_data['items'][2]['summary'])->toBe('Create "Tier" (select) on people with options: Gold, Silver');

    $service = resolve(PendingActionService::class);
    $service->approveItem($pending, $this->owner, 0);
    $service->approveItem($pending->fresh(), $this->owner, 1);
    $service->approveItem($pending->fresh(), $this->owner, 2);

    $created = CustomField::query()->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('system_defined', false)
        ->whereIn('name', ['Priority', 'Region', 'Tier'])
        ->pluck('entity_type', 'name')
        ->sortKeys()
        ->all();

    $tier = CustomField::query()->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('name', 'Tier')
        ->firstOrFail();

    TenantContextService::setTenantId($this->workspace->getKey());
    $tierOptions = CustomFieldOption::query()
        ->where('custom_field_id', $tier->getKey())
        ->orderBy('sort_order')
        ->pluck('name')
        ->all();

    expect($created)->toBe(['Priority' => 'company', 'Region' => 'company', 'Tier' => 'people'])
        ->and($tierOptions)->toBe(['Gold', 'Silver'])
        ->and($pending->refresh()->status)->toBe(PendingActionStatus::Approved);
});

it('skips a field whose name repeats an earlier one in the batch on the same entity, ignoring case', function (): void {
    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'people', 'name' => 'Tier', 'type' => 'select', 'options' => [['name' => 'Gold']]],
        ['entity_type' => 'people', 'name' => 'tier', 'type' => 'text'],
        ['entity_type' => 'company', 'name' => 'Tier', 'type' => 'text'],
    );

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->sole();

    expect($decoded['data']['_batch'])->toBeTrue()
        ->and(array_column($pending->action_data['records'], 'entity_type'))->toBe(['people', 'company'])
        ->and($decoded['skipped_records'])->toBe([
            ['record' => 'tier', 'reason' => 'A field named "tier" is already in this batch on people.'],
        ])
        ->and($decoded['skipped_note'])->toContain('NOT part of the proposal');
});

it('skips a field whose explicit code repeats an earlier one in the batch on the same entity', function (): void {
    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Region', 'type' => 'text', 'code' => 'region'],
        ['entity_type' => 'company', 'name' => 'Territory', 'type' => 'text', 'code' => 'region'],
    );

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->sole();

    expect($pending->action_data['name'])->toBe('Region')
        ->and($decoded['skipped_records'])->toBe([
            ['record' => 'Territory', 'reason' => 'A field with code "region" is already in this batch on company.'],
        ]);
});

it('skips an explicit code that matches the code generated for an earlier field in the batch', function (): void {
    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Region', 'type' => 'text'],
        ['entity_type' => 'company', 'name' => 'Territory', 'type' => 'text', 'code' => 'region'],
    );

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->sole();

    expect($pending->action_data['name'])->toBe('Region')
        ->and($decoded['skipped_records'])->toBe([
            ['record' => 'Territory', 'reason' => 'A field with code "region" is already in this batch on company.'],
        ]);
});

it('skips a field whose generated code matches an earlier explicit code in the batch', function (): void {
    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Territory', 'type' => 'text', 'code' => 'region'],
        ['entity_type' => 'company', 'name' => 'Region', 'type' => 'text'],
    );

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->sole();

    expect($pending->action_data['name'])->toBe('Territory')
        ->and($decoded['skipped_records'])->toBe([
            ['record' => 'Region', 'reason' => 'A field with code "region" is already in this batch on company.'],
        ]);
});

it('skips the fields that would cross the per-entity cap', function (): void {
    CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Founded',
        'code' => 'founded',
        'type' => 'date',
    ]);

    $cap = DB::table('custom_fields')
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->count() + 2;

    config(['chat.max_custom_fields_per_entity' => $cap]);

    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Region', 'type' => 'text'],
        ['entity_type' => 'company', 'name' => 'Industry', 'type' => 'text'],
        ['entity_type' => 'company', 'name' => 'Segment', 'type' => 'text'],
    );

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->sole();

    expect($decoded['data']['_batch'])->toBeTrue()
        ->and(array_column($pending->action_data['records'], 'name'))->toBe(['Region', 'Industry'])
        ->and($decoded['skipped_records'])->toBe([
            ['record' => 'Segment', 'reason' => "Cannot create more than {$cap} custom fields for entity type \"company\"."],
        ]);

    $service = resolve(PendingActionService::class);
    $service->approveItem($pending, $this->owner, 0);
    $service->approveItem($pending->fresh(), $this->owner, 1);

    expect(CustomField::query()->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'company')
        ->whereIn('name', ['Region', 'Industry'])
        ->count())->toBe(2);
});

it('returns one error and no proposal when every field fails', function (): void {
    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Attachment', 'type' => 'file-upload'],
        ['entity_type' => 'company', 'name' => 'Status', 'type' => 'select'],
    );

    expect($decoded['error'])->toStartWith('No proposal was created; every record failed validation.')
        ->and($decoded['error'])->toContain('Attachment: ')
        ->and($decoded['error'])->toContain('Status: ')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses more fields than one proposal holds', function (): void {
    config(['chat.max_batch_size' => 1]);

    $decoded = proposeCustomFields(
        $this->convId,
        ['entity_type' => 'company', 'name' => 'Region', 'type' => 'text'],
        ['entity_type' => 'company', 'name' => 'Industry', 'type' => 'text'],
    );

    expect($decoded['error'])->toBe('Too many records: at most 1 per proposal.')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('creates a status field with its options', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'task',
        'name' => 'Workflow',
        'type' => 'status',
        'options' => [['name' => 'Open'], ['name' => 'Shipped']],
    ]);

    expect($decoded)->not->toHaveKey('error');

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    $field = CustomField::query()
        ->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'task')
        ->where('name', 'Workflow')
        ->firstOrFail();

    TenantContextService::setTenantId($this->workspace->getKey());

    expect($field->type)->toBe('status')
        ->and(CustomFieldOption::query()->where('custom_field_id', $field->getKey())->pluck('name')->sort()->values()->all())
        ->toBe(['Open', 'Shipped']);
});

it('rejects a status field with no options', function (): void {
    $decoded = proposeCustomFields($this->convId, [
        'entity_type' => 'task',
        'name' => 'Workflow',
        'type' => 'status',
    ]);

    expect($decoded)->toHaveKey('error');
});
