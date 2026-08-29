<?php

declare(strict_types=1);

use App\Filament\Pages\Team\CustomFields;
use App\Filament\Resources\PeopleResource;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\CustomFields\Services\TenantContextService;

it('resolves a people record reference to id, type, and url', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    $person = People::factory()->for($user->currentTeam)->create(['name' => 'Angel']);

    $resolver = resolve(RecordReferenceResolver::class);
    $ref = $resolver->resolve('people', (string) $person->getKey());

    expect($ref)->toMatchArray([
        'id' => (string) $person->getKey(),
        'type' => 'people',
        'url' => PeopleResource::getUrl('view', ['record' => (string) $person->getKey()]),
    ]);
});

it('returns null for unknown entity types', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    expect(resolve(RecordReferenceResolver::class)->resolve('unknown', 'whatever'))->toBeNull();
});

it('resolves a custom field reference to the management page for its entity tab', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);
    TenantContextService::setTenantId($user->currentTeam->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $user->currentTeam->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $ref = resolve(RecordReferenceResolver::class)->resolve('custom_field', (string) $field->getKey());

    expect($ref)->not->toBeNull()
        ->and($ref['type'])->toBe('custom_field')
        ->and($ref['label'])->toBe('Age')
        ->and($ref['url'])->toContain(CustomFields::getUrl(panel: 'app', tenant: $user->currentTeam))
        ->and($ref['url'])->toContain('currentEntityType=people');

    TenantContextService::setTenantId(null);
});

it('resolves a custom field reference even when the field is deactivated', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);
    TenantContextService::setTenantId($user->currentTeam->getKey());

    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $user->currentTeam->getKey(),
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

it('does not resolve a custom field belonging to another team', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $stranger = User::factory()->withPersonalTeam()->create();

    TenantContextService::setTenantId($owner->currentTeam->getKey());
    $field = CustomField::factory()->create([
        config('custom-fields.database.column_names.tenant_foreign_key') => $owner->currentTeam->getKey(),
        'entity_type' => 'people',
        'name' => 'Age',
        'code' => 'age',
        'type' => 'number',
    ]);

    $this->actingAs($stranger);
    Filament::setTenant($stranger->currentTeam);
    TenantContextService::setTenantId($stranger->currentTeam->getKey());

    expect(resolve(RecordReferenceResolver::class)->resolve('custom_field', (string) $field->getKey()))->toBeNull();

    TenantContextService::setTenantId(null);
});
