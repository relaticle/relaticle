<?php

declare(strict_types=1);

use App\Actions\CustomFields\AddCustomFieldOptions;
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
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\CustomField\AddCustomFieldOptionsTool;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(AddCustomFieldOptionsTool::class, AddCustomFieldOptions::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $this->selectField = CustomField::factory()->create([
        $tenantKey => $this->team->getKey(),
        'entity_type' => 'company',
        'name' => 'Status',
        'type' => 'select',
        'system_defined' => false,
        'active' => true,
    ]);

    $this->selectField->options()->create([
        $tenantKey => $this->team->getKey(),
        'name' => 'Active',
        'sort_order' => 0,
    ]);

    $this->textField = CustomField::factory()->create([
        $tenantKey => $this->team->getKey(),
        'entity_type' => 'company',
        'name' => 'Description',
        'type' => 'text',
        'system_defined' => false,
        'active' => true,
    ]);

    $this->convId = '019df900-7777-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function makeAddOptionsTool(string $convId): AddCustomFieldOptionsTool
{
    $tool = resolve(AddCustomFieldOptionsTool::class);
    $tool->setConversationId($convId);

    return $tool;
}

it('proposes adding options and creates them on approval', function (): void {
    $tool = makeAddOptionsTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'Inactive'], ['name' => 'Pending']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['type'])->toBe('pending_action')
        ->and($decoded['operation'])->toBe('create')
        ->and($decoded['entity_type'])->toBe('custom_field')
        ->and($decoded['meta']['agent_should_stop'])->toBeTrue();

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($pending->action_class)->toBe(AddCustomFieldOptions::class)
        ->and($pending->operation)->toBe(PendingActionOperation::Create)
        ->and($pending->status)->toBe(PendingActionStatus::Pending);

    $service = resolve(PendingActionService::class);
    $service->approve($pending, $this->owner);

    TenantContextService::setTenantId($this->team->getKey());
    $optionNames = CustomFieldOption::query()
        ->where('custom_field_id', $this->selectField->getKey())
        ->pluck('name')
        ->sort()
        ->values()
        ->toArray();

    expect($optionNames)->toBe(['Active', 'Inactive', 'Pending']);
});

it('returns error when non-owner tries to add options', function (): void {
    $nonOwner = User::factory()->create();
    $nonOwner->teams()->attach($this->team, ['role' => 'editor']);
    $nonOwner->switchTeam($this->team);

    Auth::guard('web')->setUser($nonOwner);
    $this->actingAs($nonOwner);

    $tool = makeAddOptionsTool($this->convId);
    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'Inactive']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('returns error when adding options to a non-choice type field', function (): void {
    $tool = makeAddOptionsTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->textField->code,
        'options' => [['name' => 'Some Option']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('returns error when adding options would exceed the cap', function (): void {
    config(['chat.max_field_options' => 2]);

    $tool = makeAddOptionsTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'Too Many'], ['name' => 'Options Here']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects adding an option that already exists on the field at proposal time', function (): void {
    $tool = makeAddOptionsTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'Active']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('already exists')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects duplicate option names within one request', function (): void {
    $tool = makeAddOptionsTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'Inactive'], ['name' => 'Inactive']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects empty option names', function (): void {
    $tool = makeAddOptionsTool($this->convId);

    $result = $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => '  ']],
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveKey('error')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects approval with a friendly message when the option appeared after the proposal', function (): void {
    $tool = makeAddOptionsTool($this->convId);
    $tool->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'Fresh']],
    ]));

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    $this->selectField->options()->create([
        $tenantKey => $this->team->getKey(),
        'name' => 'Fresh',
        'sort_order' => 5,
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect(fn () => resolve(PendingActionService::class)->approve($pending, $this->owner))
        ->toThrow(ValidationException::class, 'already exists');

    TenantContextService::setTenantId($this->team->getKey());
    $freshCount = CustomFieldOption::query()
        ->where('custom_field_id', $this->selectField->getKey())
        ->where('name', 'Fresh')
        ->count();

    expect($freshCount)->toBe(1);
});

it('rejects an option that differs from an existing one only by case', function (): void {
    $decoded = json_decode((makeAddOptionsTool($this->convId))->handle(new Request([
        'entity_type' => 'company',
        'code' => $this->selectField->code,
        'options' => [['name' => 'ACTIVE']],
    ])), true);

    expect($decoded)->toHaveKey('error')
        ->and($decoded['error'])->toContain('already exists')
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('rejects a recased duplicate option at approval time, not just at proposal time', function (): void {
    expect(fn () => app(AddCustomFieldOptions::class)->execute($this->owner, [
        '_record_id' => $this->selectField->getKey(),
        'options' => [['name' => 'active']],
    ]))->toThrow(ValidationException::class, 'already exists');

    expect(CustomFieldOption::query()
        ->withoutGlobalScopes()
        ->where('custom_field_id', $this->selectField->getKey())
        ->whereRaw('lower(name) = ?', ['active'])
        ->count())->toBe(1);
});
