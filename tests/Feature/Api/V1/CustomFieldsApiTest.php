<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CustomFieldsController;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

mutates(CustomFieldsController::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();

    $this->section = CustomFieldSection::create([
        'tenant_id' => $this->team->id,
        'entity_type' => 'company',
        'name' => 'API Test',
        'code' => 'api_test',
        'type' => 'section',
        'sort_order' => 99,
        'active' => true,
    ]);

    $this->seededCount = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('active', true)
        ->count();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/custom-fields')->assertUnauthorized();
});

it('can list custom fields with expected structure', function (): void {
    Sanctum::actingAs($this->user);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_industry',
        'name' => 'Industry',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [
            'required' => true,
        ],
    ]);

    $response = $this->getJson('/api/v1/custom-fields?per_page=100');

    $response->assertOk()
        ->assertJsonCount($this->seededCount + 1, 'data');

    $field = collect($response->json('data'))
        ->first(fn ($item) => ($item['attributes']['code'] ?? null) === 'cf_industry');

    expect($field)
        ->not->toBeNull()
        ->and($field['attributes']['name'])->toBe('Industry')
        ->and($field['attributes']['type'])->toBe('text')
        ->and($field['attributes']['entity_type'])->toBe('company')
        ->and($field['attributes']['required'])->toBeTrue()
        ->and($field['attributes'])->toHaveKeys(['code', 'name', 'type', 'entity_type', 'required', 'created_at', 'updated_at'])
        ->and($field)->toHaveKeys(['id', 'type'])
        ->and($field['type'])->toBe('custom_fields');
});

it('returns required as false when field has no required rule', function (): void {
    Sanctum::actingAs($this->user);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_notes',
        'name' => 'Notes',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $response = $this->getJson('/api/v1/custom-fields?per_page=100');

    $response->assertOk();

    $field = collect($response->json('data'))
        ->first(fn ($item) => ($item['attributes']['code'] ?? null) === 'cf_notes');

    expect($field)->not->toBeNull()
        ->and($field['attributes']['required'])->toBeFalse();
});

it('can filter by entity_type', function (): void {
    Sanctum::actingAs($this->user);

    $personSection = CustomFieldSection::create([
        'tenant_id' => $this->team->id,
        'entity_type' => 'people',
        'name' => 'API Test Person',
        'code' => 'api_test_person',
        'type' => 'section',
        'sort_order' => 99,
        'active' => true,
    ]);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_industry',
        'name' => 'Industry',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $personSection->id,
        'entity_type' => 'people',
        'code' => 'cf_birthday',
        'name' => 'Birthday',
        'type' => 'date',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $response = $this->getJson('/api/v1/custom-fields?entity_type=company&per_page=100');

    $response->assertOk();

    $entityTypes = collect($response->json('data'))->pluck('attributes.entity_type')->unique();

    expect($entityTypes->all())->toBe(['company']);

    $codes = collect($response->json('data'))->pluck('attributes.code');
    expect($codes)->toContain('cf_industry');
    expect($codes)->not->toContain('cf_birthday');
});

it('does not return custom fields from other teams', function (): void {
    Sanctum::actingAs($this->user);

    $otherTeam = Team::factory()->create();

    $otherSection = CustomFieldSection::create([
        'tenant_id' => $otherTeam->id,
        'entity_type' => 'company',
        'name' => 'Other Team',
        'code' => 'other_team',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_own_field',
        'name' => 'Own Field',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    CustomField::create([
        'tenant_id' => $otherTeam->id,
        'custom_field_section_id' => $otherSection->id,
        'entity_type' => 'company',
        'code' => 'cf_secret_field',
        'name' => 'Secret Field',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $response = $this->getJson('/api/v1/custom-fields?per_page=100');

    $response->assertOk();

    $codes = collect($response->json('data'))->pluck('attributes.code');
    expect($codes)->toContain('cf_own_field');
    expect($codes)->not->toContain('cf_secret_field');
});

it('includes options for select fields', function (): void {
    Sanctum::actingAs($this->user);

    $field = CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_status',
        'name' => 'Status',
        'type' => 'select',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $field->options()->createMany([
        ['name' => 'Active', 'sort_order' => 1, 'tenant_id' => $this->team->id],
        ['name' => 'Inactive', 'sort_order' => 2, 'tenant_id' => $this->team->id],
    ]);

    $response = $this->getJson('/api/v1/custom-fields?per_page=100');

    $response->assertOk();

    $fieldData = collect($response->json('data'))
        ->first(fn ($item) => ($item['attributes']['code'] ?? null) === 'cf_status');

    expect($fieldData)->not->toBeNull()
        ->and($fieldData['attributes']['options'])->toHaveCount(2)
        ->and($fieldData['attributes']['options'][0]['label'])->toBe('Active')
        ->and($fieldData['attributes']['options'][0])->toHaveKey('value');
});

it('paginates results by default', function (): void {
    Sanctum::actingAs($this->user);

    foreach (range(1, 20) as $i) {
        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => "cf_field_{$i}",
            'name' => "Field {$i}",
            'type' => 'text',
            'sort_order' => $i,
            'active' => true,
            'validation_rules' => [],
        ]);
    }

    $response = $this->getJson('/api/v1/custom-fields');

    $response->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.per_page', 15);

    expect($response->json('data'))->toHaveCount(min(15, $this->seededCount + 20));
});

it('respects per_page parameter', function (): void {
    Sanctum::actingAs($this->user);

    foreach (range(1, 10) as $i) {
        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => "cf_page_field_{$i}",
            'name' => "Page Field {$i}",
            'type' => 'text',
            'sort_order' => $i,
            'active' => true,
            'validation_rules' => [],
        ]);
    }

    $response = $this->getJson('/api/v1/custom-fields?per_page=5');

    $response->assertOk()
        ->assertJsonPath('meta.per_page', 5);

    expect($response->json('data'))->toHaveCount(5);
});

it('caps per_page at 100', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/v1/custom-fields?per_page=200');

    $response->assertUnprocessable();
});

it('rejects non-integer per_page', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/v1/custom-fields?per_page=abc');

    $response->assertUnprocessable();
});

it('supports page navigation', function (): void {
    Sanctum::actingAs($this->user);

    foreach (range(1, 5) as $i) {
        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $this->section->id,
            'entity_type' => 'company',
            'code' => "cf_nav_field_{$i}",
            'name' => "Nav Field {$i}",
            'type' => 'text',
            'sort_order' => $i,
            'active' => true,
            'validation_rules' => [],
        ]);
    }

    $totalFields = $this->seededCount + 5;

    $page1 = $this->getJson('/api/v1/custom-fields?per_page=3');
    $page1->assertOk()
        ->assertJsonPath('meta.per_page', 3)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', $totalFields);

    expect($page1->json('data'))->toHaveCount(3);

    $page2 = $this->getJson('/api/v1/custom-fields?per_page=3&page=2');
    $page2->assertOk()
        ->assertJsonPath('meta.current_page', 2);

    expect($page2->json('data'))->toHaveCount(min(3, $totalFields - 3));
});

it('excludes inactive custom fields', function (): void {
    Sanctum::actingAs($this->user);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_active_field',
        'name' => 'Active Field',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    CustomField::create([
        'tenant_id' => $this->team->id,
        'custom_field_section_id' => $this->section->id,
        'entity_type' => 'company',
        'code' => 'cf_inactive_field',
        'name' => 'Inactive Field',
        'type' => 'text',
        'sort_order' => 2,
        'active' => false,
        'validation_rules' => [],
    ]);

    $response = $this->getJson('/api/v1/custom-fields?per_page=100');

    $response->assertOk();

    $codes = collect($response->json('data'))->pluck('attributes.code');
    expect($codes)->toContain('cf_active_field');
    expect($codes)->not->toContain('cf_inactive_field');
});
