<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\CustomField\ListCustomFieldsTool;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(ListCustomFieldsTool::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;

    Auth::guard('web')->setUser($this->owner);
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());

    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');

    $this->select = CustomField::factory()->create([
        $tenantKey => $this->team->getKey(),
        'entity_type' => 'company',
        'name' => 'Account Tier',
        'type' => 'select',
        'system_defined' => false,
        'active' => true,
    ]);
    $this->select->options()->create([
        $tenantKey => $this->team->getKey(),
        'name' => 'Gold',
        'sort_order' => 0,
    ]);

    $this->inactive = CustomField::factory()->create([
        $tenantKey => $this->team->getKey(),
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

it('does not leak custom fields from another team', function (): void {
    $otherOwner = User::factory()->withPersonalTeam()->create();
    $tenantKey = config('custom-fields.database.column_names.tenant_foreign_key');
    CustomField::factory()->create([
        $tenantKey => $otherOwner->currentTeam->getKey(),
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
