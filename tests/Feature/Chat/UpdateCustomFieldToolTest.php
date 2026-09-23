<?php

declare(strict_types=1);

use App\Actions\CustomFields\UpdateCustomField;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use App\Support\CustomFieldSettingsSchema;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\UpdateCustomFieldTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(UpdateCustomFieldTool::class, UpdateCustomField::class, CustomFieldSettingsSchema::class, CustomFieldDefinitionValidator::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $this->field = CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Industry',
        'type' => 'text',
        'system_defined' => false,
        'active' => true,
    ]);

    $this->convId = '019df900-6666-7000-8000-000000000001';
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

function makeUpdateFieldTool(string $convId): UpdateCustomFieldTool
{
    $tool = resolve(UpdateCustomFieldTool::class);
    $tool->setConversationId($convId);

    return $tool;
}

it('proposes renaming a custom field and updates name on approval', function (): void {
    $tool = makeUpdateFieldTool($this->convId);

    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Sector',
    ]]]));

    $decoded = json_decode($result, true);

    expect($decoded['type'])->toBe('pending_action')
        ->and($decoded['operation'])->toBe('update')
        ->and($decoded['entity_type'])->toBe('custom_field')
        ->and($decoded['meta']['agent_should_stop'])->toBeTrue();

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($pending->action_class)->toBe(UpdateCustomField::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Update)
        ->and($pending->status)->toBe(PendingActionStatus::Pending);

    $service = resolve(PendingActionService::class);
    $service->approve($pending, $this->owner);

    $this->field->refresh();

    expect($this->field->name)->toBe('Sector');
});

it('refuses a member with the role error and creates no proposal', function (): void {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    Auth::guard('web')->setUser($member);
    $this->actingAs($member);

    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Sector',
    ]]]));

    $decoded = json_decode($result, true);

    expect($decoded['error'])->toContain('workspace role does not allow that')
        ->and($decoded['error'])->toContain('Do not link to any page')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('returns error when trying to update a system_defined field', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $systemField = CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'System Field',
        'type' => 'text',
        'system_defined' => true,
    ]);

    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $systemField->code,
        'name' => 'Hacked',
    ]]]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('System-defined')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('proposes reactivating an inactive system-defined field, as the panel allows', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $priorityField = CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'task',
        'name' => 'Priority',
        'type' => 'select',
        'system_defined' => true,
        'active' => false,
    ]);

    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'task',
        'code' => $priorityField->code,
        'active' => true,
    ]]]));

    expect(json_decode($result, true)['type'])->toBe('pending_action')
        ->and(PendingAction::query()->where('entity_type', 'custom_field')->count())->toBe(1);
});

it('refuses to deactivate a system-defined field', function (): void {
    $amount = makeSystemAmountField($this);

    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'opportunity',
        'code' => $amount->code,
        'active' => false,
    ]]]));

    expect($result)->toContain('System-defined')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects renaming to a name that already exists on the entity at proposal time', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Sector',
        'code' => 'sector',
        'type' => 'text',
    ]);

    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Sector',
    ]]]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('already exists')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('allows renaming a field to its own current name', function (): void {
    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Industry',
    ]]]));

    $decoded = json_decode($result, true);

    expect($decoded['type'])->toBe('pending_action');
});

it('rejects approval when the new name was taken after the proposal', function (): void {
    $tool = makeUpdateFieldTool($this->convId);
    $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Sector',
    ]]]));

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Sector',
        'code' => 'sector',
        'type' => 'text',
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->owner))
        ->toThrow(ValidationException::class, 'already exists');

    expect($this->field->refresh()->name)->toBe('Industry');
});

it('rejects renaming to a name longer than 50 characters', function (): void {
    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => str_repeat('a', 51),
    ]]]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('proposes toggling active status and applies it on approval', function (): void {
    $tool = makeUpdateFieldTool($this->convId);

    $result = $tool->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'active' => false,
    ]]]));

    $decoded = json_decode($result, true);
    expect($decoded['type'])->toBe('pending_action');

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    $service = resolve(PendingActionService::class);
    $service->approve($pending, $this->owner);

    TenantContextService::setTenantId($this->workspace->getKey());
    $refreshed = CustomField::query()
        ->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->find($this->field->getKey());

    expect($refreshed->active)->toBeFalse();
});

it('batches several field definitions into one per-item proposal', function (): void {
    $second = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $this->workspace->getKey(), 'entity_type' => 'company', 'name' => 'Region', 'code' => 'region', 'type' => 'text', 'active' => true,
    ]);

    $tool = makeUpdateFieldTool($this->convId);

    $result = json_decode($tool->handle(new Request(['records' => [
        ['entity_type' => 'company', 'code' => $this->field->code, 'name' => 'Sector'],
        ['entity_type' => 'company', 'code' => $second->code, 'active' => false],
    ]])), true);

    $pending = PendingAction::query()->latest()->firstOrFail();

    expect($result['data']['_batch'])->toBeTrue()
        ->and($pending->action_data['records'])->toHaveCount(2)
        ->and($pending->action_data['records'][1]['_model_class'])->toBe(CustomField::class)
        ->and($pending->display_data['summary'])->toBe('Update 2 custom fields');

    $service = resolve(PendingActionService::class);
    $service->approveItem($pending, $this->owner, 0);
    $service->approveItem($pending->fresh(), $this->owner, 1);

    expect($this->field->refresh()->name)->toBe('Sector')
        ->and(CustomField::withoutGlobalScopes()->find($second->getKey())->active)->toBeFalse();
});

function makeSystemAmountField(object $context): CustomField
{
    return CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $context->workspace->getKey(),
        'entity_type' => 'opportunity',
        'name' => 'Amount',
        'type' => 'currency',
        'system_defined' => true,
        'active' => true,
        'settings' => new CustomFieldSettingsData(additional: [
            'currency_code' => 'USD',
            'display_type' => 'symbol',
            'decimal_places' => 2,
        ]),
    ]);
}

it('proposes removing cents from a system currency field and applies it on approval', function (): void {
    $amount = makeSystemAmountField($this);

    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'opportunity',
        'code' => $amount->code,
        'settings' => ['decimal_places' => 0],
    ]]]));

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->latest('id')->firstOrFail();

    expect(json_decode($result, true)['type'])->toBe('pending_action')
        ->and($pending->display_data['fields'])->toContainEqual([
            'label' => __('custom-fields::custom-fields.currency.decimal_places'),
            'old' => '2',
            'new' => '0',
        ]);

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    $amount->refresh();

    expect($amount->getCurrencySettings()->decimalPlaces)->toBe(0)
        ->and($amount->getCurrencySettings()->currencyCode)->toBe('USD')
        ->and($amount->name)->toBe('Amount');
});

it('applies a list visibility setting on a regular field', function (): void {
    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'settings' => ['visible_in_list' => false],
    ]]]));

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->latest('id')->firstOrFail();

    expect(json_decode($result, true)['type'])->toBe('pending_action');

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    expect($this->field->fresh()->settings->visible_in_list)->toBeFalse();
});

it('refuses a setting that does not apply to the field type and names the ones that do', function (): void {
    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'settings' => ['decimal_places' => 0],
    ]]]));

    expect($result)->toContain('decimal_places')
        ->and($result)->toContain('visible_in_list')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses settings the panel locks once a field exists', function (): void {
    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $this->field->code,
        'settings' => ['encrypted' => true],
    ]]]));

    expect($result)->toContain('encrypted')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses a uniqueness change on a system field', function (): void {
    $systemText = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Domains',
        'type' => 'text',
        'system_defined' => true,
        'active' => true,
    ]);

    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $systemText->code,
        'settings' => ['unique_per_entity_type' => true],
    ]]]));

    expect($result)->toContain('unique_per_entity_type')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses a decimal count the panel does not offer and names the ones it does', function (): void {
    $amount = makeSystemAmountField($this);

    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'opportunity',
        'code' => $amount->code,
        'settings' => ['decimal_places' => 7],
    ]]]));

    expect($result)->toContain('decimal_places must be 0, 2, 3 or 4')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('keeps the decimals when only the currency changes on a field still using its defaults', function (): void {
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where(config('custom-fields.database.column_names.tenant_foreign_key'), $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();

    expect($amount->settings->additional)->toBe([]);

    makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'opportunity',
        'code' => 'amount',
        'settings' => ['currency_code' => 'JPY'],
    ]]]));

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->latest('id')->firstOrFail();

    expect($pending->display_data['fields'])->toHaveCount(1);

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    $amount->refresh();

    expect($amount->getCurrencySettings()->currencyCode)->toBe('JPY')
        ->and($amount->getCurrencySettings()->decimalPlaces)->toBe(2);
});

it('raises the value limit when allowing multiple values, as the panel toggle does', function (): void {
    $email = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Billing emails',
        'type' => 'email',
        'system_defined' => false,
        'active' => true,
    ]);

    makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $email->code,
        'settings' => ['allow_multiple' => true],
    ]]]));

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->latest('id')->firstOrFail();

    expect($pending->display_data['fields'])->toContainEqual([
        'label' => __('custom-fields::custom-fields.field.form.max_values'),
        'old' => '1',
        'new' => '2',
    ]);

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    expect($email->fresh()->settings->allow_multiple)->toBeTrue()
        ->and($email->fresh()->settings->max_values)->toBe(2);
});

it('re-validates settings at approval time', function (): void {
    $amount = makeSystemAmountField($this);

    expect(fn () => resolve(UpdateCustomField::class)->execute($this->owner, $amount, ['settings' => ['encrypted' => true]]))
        ->toThrow(ValidationException::class);
});

it('leaves the currency settings unwritten when only a shared setting changes', function (): void {
    $budget = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Budget',
        'type' => 'currency',
        'system_defined' => false,
        'active' => true,
        'settings' => new CustomFieldSettingsData,
    ]);

    makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'company',
        'code' => $budget->code,
        'settings' => ['visible_in_list' => false],
    ]]]));

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->latest('id')->firstOrFail();

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    expect($budget->fresh()->settings->visible_in_list)->toBeFalse()
        ->and($budget->fresh()->settings->additional)->toBe([]);
});

it('lets an admin remove cents from a currency field on approval', function (): void {
    $amount = makeSystemAmountField($this);

    $admin = User::factory()->create();
    $admin->workspaces()->attach($this->workspace, ['role' => 'admin']);
    $admin->switchWorkspace($this->workspace);

    Auth::guard('web')->setUser($admin);
    $this->actingAs($admin);

    $result = makeUpdateFieldTool($this->convId)->handle(new Request(['records' => [[
        'entity_type' => 'opportunity',
        'code' => $amount->code,
        'settings' => ['decimal_places' => 0],
    ]]]));

    expect(json_decode($result, true)['type'])->toBe('pending_action');

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->latest('id')->firstOrFail();

    resolve(PendingActionService::class)->approve($pending, $admin);

    expect($amount->refresh()->getCurrencySettings()->decimalPlaces)->toBe(0);
});
