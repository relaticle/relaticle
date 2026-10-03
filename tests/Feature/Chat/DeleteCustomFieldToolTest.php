<?php

declare(strict_types=1);

use App\Actions\CustomFields\DeleteCustomField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CustomFieldDefinitionValidator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\DeleteCustomFieldTool;
use Relaticle\CustomFields\Services\TenantContextService;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(DeleteCustomFieldTool::class, DeleteCustomField::class, CustomFieldDefinitionValidator::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $this->field = deletableField($this->workspace, 'industry', 'Industry');

    $this->convId = '019df900-8888-7000-8000-000000000005';
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
 * @param  array<string, mixed>  $attributes
 */
function deletableField(Workspace $workspace, string $code, string $name, array $attributes = []): CustomField
{
    return CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => 'company',
        'code' => $code,
        'name' => $name,
        'type' => 'text',
        'system_defined' => false,
        'active' => true,
        ...$attributes,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $records
 * @return array<string, mixed>
 */
function proposeFieldDelete(string $convId, array $records): array
{
    $tool = resolve(DeleteCustomFieldTool::class);
    $tool->setConversationId($convId);

    return json_decode($tool->handle(new Request(['records' => $records])), true);
}

function fieldExists(CustomField $field): bool
{
    return DB::table('custom_fields')->where('id', $field->getKey())->exists();
}

function storedValueCount(CustomField $field): int
{
    return DB::table('custom_field_values')->where('custom_field_id', $field->getKey())->count();
}

it('proposes deleting a field and removes it with its options on approval', function (): void {
    $select = deletableField($this->workspace, 'tier', 'Tier', ['type' => 'select']);

    CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $select->getKey(),
        'name' => 'Gold',
        'sort_order' => 1,
    ]);

    $result = proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'tier']]);

    expect($result['type'])->toBe('pending_action')
        ->and($result['operation'])->toBe('delete')
        ->and($result['entity_type'])->toBe('custom_field')
        ->and($result['data'])->toBe(['records' => [['entity_type' => 'company', 'code' => 'tier']]])
        ->and($result['meta']['agent_should_stop'])->toBeTrue();

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($pending->action_class)->toBe(DeleteCustomField::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Delete)
        ->and($pending->status)->toBe(PendingActionStatus::Pending)
        ->and($pending->action_data)->toBe(['_record_ids' => [$select->getKey()], '_model_class' => CustomField::class])
        ->and($pending->display_data['summary'])->toBe('Delete custom field "Tier"')
        ->and(collect($pending->display_data['fields'])->pluck('value', 'label')->all())->toBe([
            'Name' => 'Tier',
            'Record type' => 'Company',
            'Values deleted' => 'None. No record holds a value for it.',
            'Options deleted' => 'Gold',
        ]);

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    expect(fieldExists($select))->toBeFalse()
        ->and(DB::table('custom_field_options')->where('custom_field_id', $select->getKey())->count())->toBe(0)
        ->and(fieldExists($this->field))->toBeTrue();
});

it('deletes an inactive field together with the values records hold, and says how many on the card', function (): void {
    Company::factory()->count(2)->for($this->workspace)->create()
        ->each(fn (Company $company) => $company->saveCustomFieldValue($this->field, 'SaaS'));
    $this->field->deactivate();

    proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'industry']]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(collect($pending->display_data['fields'])->pluck('value', 'label')->all())
        ->toBe(['Name' => 'Industry', 'Record type' => 'Company', 'Values deleted' => 'The values on 2 records'])
        ->and(storedValueCount($this->field))->toBe(2);

    resolve(PendingActionService::class)->approve($pending, $this->owner);

    expect(fieldExists($this->field))->toBeFalse()
        ->and(storedValueCount($this->field))->toBe(0);
});

it('refuses an active field that records still hold values for and names deactivation', function (): void {
    Company::factory()->for($this->workspace)->create()->saveCustomFieldValue($this->field, 'SaaS');

    $result = proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'industry']]);

    expect($result['error'])->toContain('Deactivate the field first')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0)
        ->and(fieldExists($this->field))->toBeTrue();
});

it('refuses a system-defined field', function (): void {
    deletableField($this->workspace, 'locked', 'Locked', ['system_defined' => true, 'active' => false]);

    $result = proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'locked']]);

    expect($result['error'])->toContain('system-defined field and cannot be deleted')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('refuses a member with the role error and creates no proposal', function (): void {
    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    Auth::guard('web')->setUser($member);
    $this->actingAs($member);

    $result = proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'industry']]);

    expect($result['error'])->toContain('workspace role does not allow that')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('does not reach a field of another workspace', function (): void {
    $other = User::factory()->withPersonalWorkspace()->create();
    $foreign = deletableField($other->currentWorkspace, 'foreign_only', 'Foreign');

    $result = proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'foreign_only']]);

    expect($result['error'])->toContain('No custom field with code "foreign_only"')
        ->and(fieldExists($foreign))->toBeTrue();
});

it('refuses the same field twice in one proposal', function (): void {
    $result = proposeFieldDelete($this->convId, [
        ['entity_type' => 'company', 'code' => 'industry'],
        ['entity_type' => 'company', 'code' => 'industry'],
    ]);

    expect($result['error'])->toContain('already in this proposal')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('fails approval when a record got a value after the proposal, and keeps the field', function (): void {
    proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'industry']]);

    Company::factory()->for($this->workspace)->create()->saveCustomFieldValue($this->field, 'SaaS');

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->owner))
        ->toThrow(ValidationException::class, 'Deactivate the field first');

    expect(fieldExists($this->field))->toBeTrue()
        ->and(storedValueCount($this->field))->toBe(1);
});

it('refuses a member who approves a proposal an owner filed', function (): void {
    proposeFieldDelete($this->convId, [['entity_type' => 'company', 'code' => 'industry']]);

    $member = User::factory()->create();
    $member->workspaces()->attach($this->workspace, ['role' => 'member']);
    $member->switchWorkspace($this->workspace);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $member))
        ->toThrow(HttpException::class);

    expect(fieldExists($this->field))->toBeTrue();
});

it('batches several fields into one per-item proposal and deletes each on its own approval', function (): void {
    $region = deletableField($this->workspace, 'region', 'Region');
    $legacy = deletableField($this->workspace, 'legacy', 'Legacy', ['active' => false]);

    $result = proposeFieldDelete($this->convId, [
        ['entity_type' => 'company', 'code' => 'industry'],
        ['entity_type' => 'company', 'code' => 'region'],
        ['entity_type' => 'company', 'code' => 'legacy'],
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($result['data']['records'])->toHaveCount(3)
        ->and($pending->action_data['_batch'])->toBeTrue()
        ->and($pending->action_data['records'][2])->toBe(['_record_id' => $legacy->getKey(), '_model_class' => CustomField::class])
        ->and($pending->display_data['summary'])->toBe('Delete 3 custom fields')
        ->and($pending->display_data['items'])->toHaveCount(3);

    DB::table('custom_fields')->where('id', $region->getKey())->delete();

    $service = resolve(PendingActionService::class);
    $service->approveItem($pending, $this->owner, 0);
    expect(fn () => $service->approveItem($pending->fresh(), $this->owner, 1))->toThrow(RuntimeException::class);
    $service->approveItem($pending->fresh(), $this->owner, 2);

    expect(fieldExists($this->field))->toBeFalse()
        ->and(fieldExists($legacy))->toBeFalse();
});
