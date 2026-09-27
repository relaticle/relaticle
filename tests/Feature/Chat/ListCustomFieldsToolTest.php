<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\CustomField\ListCustomFieldsTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(ListCustomFieldsTool::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

    $this->select = CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Account Tier',
        'type' => 'select',
        'system_defined' => false,
        'active' => true,
    ]);
    $this->select->options()->create([
        $tenantKey => $this->workspace->getKey(),
        'name' => 'Gold',
        'sort_order' => 0,
    ]);

    $this->inactive = CustomField::factory()->create([
        $tenantKey => $this->workspace->getKey(),
        'entity_type' => 'opportunity',
        'name' => 'Legacy',
        'type' => 'text',
        'system_defined' => false,
        'active' => false,
    ]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('lists custom field definitions with code, type, active status and options', function (): void {
    $result = resolve(ListCustomFieldsTool::class)->handle(new Request([]));
    $decoded = json_decode($result, true);

    $fields = collect($decoded['custom_fields']);

    $account = $fields->firstWhere('code', $this->select->code);
    expect($account)->not->toBeNull()
        ->and($account['entity_type'])->toBe('company')
        ->and($account['name'])->toBe('Account Tier')
        ->and($account['type'])->toBe('select')
        ->and($account['active'])->toBeTrue()
        ->and($account['options'])->toContain('Gold');

    $legacy = $fields->firstWhere('code', $this->inactive->code);
    expect($legacy)->not->toBeNull()
        ->and($legacy['active'])->toBeFalse();
});

it('filters by entity_type', function (): void {
    $result = resolve(ListCustomFieldsTool::class)->handle(new Request(['entity_type' => 'company']));
    $decoded = json_decode($result, true);

    $entities = collect($decoded['custom_fields'])->pluck('entity_type')->unique()->values()->all();

    expect($entities)->toBe(['company']);
});

it('does not leak custom fields from another workspace', function (): void {
    $otherOwner = User::factory()->withPersonalWorkspace()->create();
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $otherOwner->currentWorkspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Secret Field',
        'type' => 'text',
        'system_defined' => false,
        'active' => true,
    ]);

    $result = resolve(ListCustomFieldsTool::class)->handle(new Request([]));
    $codes = collect(json_decode($result, true)['custom_fields'])->pluck('name')->all();

    expect($codes)->not->toContain('Secret Field');
});

it('returns the current settings and the settable ones so a change starts from the real value', function (): void {
    $amount = CustomField::query()
        ->withoutGlobalScopes()
        ->where(config('custom-fields.database.column_names.tenant_foreign_key'), $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();

    $amount->forceFill(['settings' => new CustomFieldSettingsData(additional: [
        'currency_code' => 'EUR',
        'display_type' => 'code',
        'decimal_places' => 0,
    ])])->save();

    $payload = json_decode(resolve(ListCustomFieldsTool::class)->handle(new Request(['entity_type' => 'opportunity'])), true);
    $amount = collect($payload['custom_fields'])->firstWhere('code', 'amount');

    expect($amount['settings'])->toMatchArray([
        'currency_code' => 'EUR',
        'display_type' => 'code',
        'decimal_places' => 0,
    ])
        ->and($amount['settings'])->toHaveKey('visible_in_list');
});

it('offers uniqueness on a regular text field but not on a system one', function (): void {
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

    foreach ([['Region', false], ['Legal name', true]] as [$name, $systemDefined]) {
        CustomField::factory()->create([
            $tenantKey => $this->workspace->getKey(),
            'entity_type' => 'people',
            'name' => $name,
            'type' => 'text',
            'system_defined' => $systemDefined,
            'active' => true,
        ]);
    }

    $payload = json_decode(resolve(ListCustomFieldsTool::class)->handle(new Request(['entity_type' => 'people'])), true);
    $fields = collect($payload['custom_fields']);

    expect($fields->firstWhere('name', 'Region')['settings'])->toHaveKey('unique_per_entity_type')
        ->and($fields->firstWhere('name', 'Legal name')['settings'])->not->toHaveKey('unique_per_entity_type');
});
