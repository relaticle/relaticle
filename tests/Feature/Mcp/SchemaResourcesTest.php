<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Mcp\Resources\CompanySchemaResource;
use App\Mcp\Resources\Concerns\ResolvesEntitySchema;
use App\Mcp\Resources\NoteSchemaResource;
use App\Mcp\Resources\OpportunitySchemaResource;
use App\Mcp\Resources\PeopleSchemaResource;
use App\Mcp\Resources\TaskSchemaResource;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(
    AppServiceProvider::class,
    CompanySchemaResource::class,
    CustomFieldFilterSchema::class,
    GetCrmSchemaTool::class,
    NoteSchemaResource::class,
    OpportunitySchemaResource::class,
    PeopleSchemaResource::class,
    ResolvesEntitySchema::class,
    TaskSchemaResource::class,
);

beforeEach(function (): void {
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

it('publishes complete task and note output contracts', function (string $entityType, string $relationship, string $toolsHint): void {
    $toolContract = resolve(GetCrmSchemaTool::class)->toArray();

    expect($toolContract)
        ->toHaveKey('outputSchema.properties.writable_relationships.type', 'object')
        ->toHaveKey('outputSchema.properties.tools_hint.type', 'string');

    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => $entityType])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has("writable_relationships.{$relationship}")
            ->where('tools_hint', $toolsHint)
            ->etc());
})->with([
    'task' => [
        'task',
        'assignee_ids',
        'Use attach-task-to-entities and detach-task-from-entities tools for post-creation relationship management.',
    ],
    'note' => [
        'note',
        'company_ids',
        'Use attach-note-to-entities and detach-note-from-entities tools for post-creation relationship management.',
    ],
]);

it('includes custom fields in schema when they exist', function (): void {
    $team = $this->user->personalTeam();

    $section = CustomFieldSection::factory()->create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Test Section',
        'code' => 'test_section',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    CustomField::factory()->create([
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

    $section = CustomFieldSection::factory()->create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Required Section',
        'code' => 'required_section',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    CustomField::factory()->create([
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

it('describes hyphenated choice and datetime field types correctly', function (): void {
    $team = $this->user->personalTeam();
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Advanced Fields',
        'code' => 'advanced_fields',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    $multiSelect = CustomField::query()->create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $toggleButtons = CustomField::query()->create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'priority',
        'name' => 'Priority',
        'type' => 'toggle-buttons',
        'sort_order' => 2,
        'active' => true,
        'validation_rules' => [],
    ]);

    CustomField::query()->create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'renewal_at',
        'name' => 'Renewal At',
        'type' => 'date-time',
        'sort_order' => 3,
        'active' => true,
        'validation_rules' => [],
    ]);

    foreach ([$multiSelect, $toggleButtons] as $index => $field) {
        CustomFieldOption::query()->create([
            'tenant_id' => $team->id,
            'custom_field_id' => $field->id,
            'name' => $index === 0 ? 'Enterprise' : 'High',
            'sort_order' => 1,
        ]);
    }

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee('array of option labels or IDs (see options)')
        ->assertSee('option label or option ID (see options)')
        ->assertSee('ISO 8601 datetime string')
        ->assertSee('Enterprise')
        ->assertSee('High');
});

it('describes tags-input values as arbitrary strings instead of option IDs', function (): void {
    $team = $this->user->personalTeam();
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Tag Fields',
        'code' => 'tag_fields',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);

    CustomField::query()->create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'labels',
        'name' => 'Labels',
        'type' => 'tags-input',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    $expected = implode("\n", [
        '        "labels": {',
        '            "name": "Labels",',
        '            "type": "tags-input",',
        '            "required": false,',
        '            "input_format": "array of arbitrary string values",',
        '            "example": [',
        '                "priority",',
        '                "customer"',
        '            ]',
    ]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee($expected);
});

it('serializes empty custom-field maps as objects in resources and tools', function (): void {
    $team = $this->user->personalTeam();

    CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'company')
        ->update(['active' => false]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee('"custom_fields": {}')
        ->assertSee('"filterable_fields": {}');

    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => 'company'])
        ->assertOk()
        ->assertSee('"custom_fields":{}')
        ->assertSee('"filterable_fields":{}');
});

it('invalidates the entity schema cache when an option changes', function (): void {
    $team = $this->user->personalTeam();
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Cached Fields',
        'code' => 'cached_fields',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);
    $field = CustomField::query()->create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'segment',
        'name' => 'Segment',
        'type' => 'select',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertDontSee('Enterprise Segment');

    CustomFieldOption::query()->create([
        'tenant_id' => $team->id,
        'custom_field_id' => $field->id,
        'name' => 'Enterprise Segment',
        'sort_order' => 1,
    ]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee('Enterprise Segment');
});

it('publishes an input format for every custom field type', function (string $type, string $expectedFormat): void {
    $team = $this->user->personalTeam();
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $team->id,
        'entity_type' => 'company',
        'name' => 'Types',
        'code' => 'types',
        'type' => 'section',
        'sort_order' => 1,
        'active' => true,
    ]);
    CustomField::query()->create([
        'tenant_id' => $team->id,
        'custom_field_section_id' => $section->id,
        'entity_type' => 'company',
        'code' => 'probe',
        'name' => 'Probe',
        'type' => $type,
        'lookup_type' => $type === 'record' ? 'company' : null,
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class)
        ->assertOk()
        ->assertSee($expectedFormat);
})->with(customFieldHintRows());

/** @return list<array{0: string, 1: string}> */
function customFieldHintRows(): array
{
    return [
        ['text', '"input_format": "string"'],
        ['textarea', '"input_format": "string"'],
        ['number', 'numeric value'],
        ['currency', 'numeric value (amount)'],
        ['email', 'array of email strings'],
        ['phone', 'array of phone strings'],
        ['link', 'array of URL strings'],
        ['checkbox', '"input_format": "boolean"'],
        ['toggle', '"input_format": "boolean"'],
        ['select', 'option label or option ID (see options)'],
        ['radio', 'option label or option ID (see options)'],
        ['toggle-buttons', 'option label or option ID (see options)'],
        ['multi-select', 'array of option labels or IDs (see options)'],
        ['checkbox-list', 'array of option labels or IDs (see options)'],
        ['tags-input', 'array of arbitrary string values'],
        ['rich-editor', 'markdown, or HTML when the value starts with'],
        ['color-picker', 'hex color string'],
        ['date', 'ISO 8601 date"'],
        ['date-time', 'ISO 8601 datetime string'],
        ['record', 'array of record IDs of the lookup entity'],
    ];
}

it('exercises the hint of every custom field type a tenant can create', function (): void {
    $exercised = array_column(customFieldHintRows(), 0);

    $creatable = array_values(array_diff(
        array_map(fn (CustomFieldType $case): string => $case->value, CustomFieldType::cases()),
        // file-upload is disabled product-wide (config/custom-fields.php) and its writes ship in #699.
        [CustomFieldType::FILE_UPLOAD->value],
    ));

    expect($exercised)->toEqualCanonicalizing($creatable);
});
