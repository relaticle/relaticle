<?php

declare(strict_types=1);

use App\Actions\CustomFields\UpdateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\UpdateCustomFieldTool;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(UpdateCustomFieldTool::class, UpdateCustomField::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $this->field = CustomField::factory()->create([
        $tenantKey => $this->team->getKey(),
        'entity_type' => 'company',
        'name' => 'Industry',
        'type' => 'text',
        'system_defined' => false,
        'active' => true,
    ]);

    $this->convId = '019df900-6666-7000-8000-000000000001';
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

function makeUpdateFieldTool(string $convId): UpdateCustomFieldTool
{
    $tool = resolve(UpdateCustomFieldTool::class);
    $tool->setConversationId($convId);

    return $tool;
}

it('proposes renaming a custom field and updates name on approval', function (): void {
    $tool = makeUpdateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Sector',
    ]));

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

it('returns error and creates no proposal for non-owner', function (): void {
    $nonOwner = User::factory()->create();
    $nonOwner->teams()->attach($this->team, ['role' => 'editor']);
    $nonOwner->switchTeam($this->team);

    Auth::guard('web')->setUser($nonOwner);
    $this->actingAs($nonOwner);

    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->field->code,
        'name' => 'Sector',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('returns error when trying to update a system_defined field', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $systemField = CustomField::factory()->create([
        $tenantKey => $this->team->getKey(),
        'entity_type' => 'company',
        'name' => 'System Field',
        'type' => 'text',
        'system_defined' => true,
    ]);

    $tool = makeUpdateFieldTool($this->convId);
    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $systemField->code,
        'name' => 'Hacked',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('proposes toggling active status and applies it on approval', function (): void {
    $tool = makeUpdateFieldTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->field->code,
        'active' => false,
    ]));

    $decoded = json_decode($result, true);
    expect($decoded['type'])->toBe('pending_action');

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    $service = resolve(PendingActionService::class);
    $service->approve($pending, $this->owner);

    TenantContextService::setTenantId($this->team->getKey());
    $refreshed = CustomField::query()
        ->withoutGlobalScope(CustomFieldsActivableScope::class)
        ->find($this->field->getKey());

    expect($refreshed->active)->toBeFalse();
});
