<?php

declare(strict_types=1);

use App\Mcp\Resources\CompanySchemaResource;
use App\Mcp\Resources\NoteSchemaResource;
use App\Mcp\Resources\OpportunitySchemaResource;
use App\Mcp\Resources\PeopleSchemaResource;
use App\Mcp\Resources\TaskSchemaResource;
use App\Mcp\Servers\RelaticleServer;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
});

it('returns valid company schema with correct fields', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee('"entity"')
        ->assertSee('company')
        ->assertSee('"name"')
        ->assertSee('"relationships"')
        ->assertSee('"custom_fields"');
});

it('returns valid people schema with correct fields', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(PeopleSchemaResource::class)
        ->assertOk()
        ->assertSee('people')
        ->assertSee('"name"')
        ->assertSee('"company_id"');
});

it('returns valid opportunity schema with correct fields', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(OpportunitySchemaResource::class)
        ->assertOk()
        ->assertSee('opportunity')
        ->assertSee('"company_id"')
        ->assertSee('"contact_id"');
});

it('returns valid task schema with correct fields', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(TaskSchemaResource::class)
        ->assertOk()
        ->assertSee('task')
        ->assertSee('"title"');
});

it('returns valid note schema with correct fields', function (): void {
    RelaticleServer::actingAs($this->user)
        ->resource(NoteSchemaResource::class)
        ->assertOk()
        ->assertSee('note')
        ->assertSee('"title"');
});

it('includes custom fields in schema when they exist', function (): void {
    $team = $this->user->personalTeam();

    $section = CustomFieldSection::create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Test Section',
        'code' => 'test_section',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    CustomField::create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'test_field',
        'name' => 'Test Field',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee('test_field')
        ->assertSee('Test Field');
});

it('reports a required custom field as required in the schema', function (): void {
    $team = $this->user->personalTeam();

    $section = CustomFieldSection::create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Required Section',
        'code' => 'required_section',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    CustomField::create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'must_have',
        'name' => 'Must Have',
        'type' => 'text',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => ['required' => true],
    ]);

    // The old predicate read the pre-migration array-of-objects shape, so every field
    // reported required:false and an agent could not tell what it had to supply.
    // Asserted on this field's own entry. A bare '"required": true' scan is vacuous:
    // the CORE `name` field is always required, so it matches even when every custom
    // field reports false, which is exactly the defect this pins.
    $expected = implode("\n", [
        '        "must_have": {',
        '            "name": "Must Have",',
        '            "type": "text",',
        '            "required": true',
    ]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee($expected);
});
