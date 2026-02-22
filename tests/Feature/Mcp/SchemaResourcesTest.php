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
