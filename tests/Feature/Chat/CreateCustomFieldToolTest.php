<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\CreateCustomFieldTool;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(CreateCustomFieldTool::class, CreateCustomField::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $this->convId = '019df900-5555-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
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

it('creates a pending proposal for a select field with options', function (): void {
    $tool = makeCreateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High'], ['name' => 'Low']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['type'])->toBe('pending_action')
        ->and($decoded['operation'])->toBe('create')
        ->and($decoded['entity_type'])->toBe('custom_field')
        ->and($decoded['meta']['agent_should_stop'])->toBeTrue();

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($pending->action_class)->toBe(CreateCustomField::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Create)
        ->and($pending->entity_type)->toBe('custom_field')
        ->and($pending->action_data['name'])->toBe('Priority')
        ->and($pending->action_data['type'])->toBe('select')
        ->and($pending->action_data['entity_type'])->toBe('company')
        ->and($pending->action_data['options'])->toHaveCount(2)
        ->and($pending->status)->toBe(PendingActionStatus::Pending);
});

it('executes the approved proposal and creates the field + options in the database', function (): void {
    $tool = makeCreateFieldTool($this->convId);

    $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High'], ['name' => 'Low']],
    ]));

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    $service = resolve(PendingActionService::class);
    $service->approve($pending, $this->owner);

    $field = CustomField::query()->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'company')
        ->where('name', 'Priority')
        ->first();

    expect($field)->not->toBeNull()
        ->and($field->type)->toBe('select')
        ->and($field->active)->toBeTrue()
        ->and($field->system_defined)->toBeFalse();

    TenantContextService::setTenantId($this->team->getKey());
    $optionNames = CustomFieldOption::query()
        ->where('custom_field_id', $field->getKey())
        ->pluck('name')
        ->sort()
        ->values()
        ->toArray();

    expect($optionNames)->toBe(['High', 'Low']);
});

it('returns error and creates no proposal when a non-owner invokes the tool', function (): void {
    $nonOwner = User::factory()->create();
    $nonOwner->teams()->attach($this->team, ['role' => 'editor']);
    $nonOwner->switchTeam($this->team);

    Auth::guard('web')->setUser($nonOwner);
    $this->actingAs($nonOwner);

    $tool = makeCreateFieldTool($this->convId);
    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'Priority',
        'type' => 'select',
        'options' => [['name' => 'High']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('returns error for a non-allowlisted field type', function (): void {
    $tool = makeCreateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'Attachment',
        'type' => 'file-upload',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects when over the max_custom_fields_per_entity cap', function (): void {
    config(['chat.max_custom_fields_per_entity' => 2]);

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

    CustomField::factory()->count(2)->create([
        $tenantKey => $this->team->getKey(),
        'entity_type' => 'company',
    ]);

    $tool = makeCreateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'One More',
        'type' => 'text',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('requires options for choice types', function (): void {
    $tool = makeCreateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'Status',
        'type' => 'select',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('creates a text field without options successfully', function (): void {
    $tool = makeCreateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'name' => 'Website',
        'type' => 'text',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['type'])->toBe('pending_action')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(1);
});
