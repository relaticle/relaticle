<?php

declare(strict_types=1);

use App\Filament\Pages\Workspace\CustomFields;
use App\Filament\Resources\PeopleResource;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\CustomFields\Services\TenantContextService;

it('resolves a people record reference to id, type, and url', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $person = People::factory()->for($user->currentWorkspace)->create(['name' => 'Angel']);

    $resolver = resolve(RecordReferenceResolver::class);
    $ref = $resolver->resolve('people', (string) $person->getKey());

    expect($ref)->toMatchArray([
        'id' => (string) $person->getKey(),
        'type' => 'people',
        'url' => PeopleResource::getUrl('view', ['record' => (string) $person->getKey()]),
    ]);
});

it('returns null for unknown entity types', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    expect(resolve(RecordReferenceResolver::class)->resolve('unknown', 'whatever'))->toBeNull();
});

it('resolves a custom field reference to the management page for its entity tab', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $user->currentWorkspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $ref = resolve(RecordReferenceResolver::class)->resolve('custom_field', (string) $field->getKey());

    expect($ref)->not->toBeNull()
        ->and($ref['type'])->toBe('custom_field')
        ->and($ref['label'])->toBe('Age')
        ->and($ref['url'])->toContain(CustomFields::getUrl(panel: 'app', tenant: $user->currentWorkspace))
        ->and($ref['url'])->toContain('currentEntityType=people');

    TenantContextService::setTenantId(null);
});

it('resolves a custom field reference even when the field is deactivated', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $user->currentWorkspace->getKey(),
        'entity_type' => 'company',
        'name' => 'Retired',
        'code' => 'retired',
        'type' => 'text',
        'active' => false,
    ]);

    $ref = resolve(RecordReferenceResolver::class)->resolve('custom_field', (string) $field->getKey());

    expect($ref)->not->toBeNull()
        ->and($ref['url'])->toContain('currentEntityType=company');

    TenantContextService::setTenantId(null);
});

it('does not resolve a custom field belonging to another workspace', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $stranger = User::factory()->withPersonalWorkspace()->create();

    TenantContextService::setTenantId($owner->currentWorkspace->getKey());
    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $owner->currentWorkspace->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $this->actingAs($stranger);
    Filament::setTenant($stranger->currentWorkspace);
    TenantContextService::setTenantId($stranger->currentWorkspace->getKey());

    expect(resolve(RecordReferenceResolver::class)->resolve('custom_field', (string) $field->getKey()))->toBeNull();

    TenantContextService::setTenantId(null);
});
