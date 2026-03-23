<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Mcp\Tools\Note\CreateNoteTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Note\UpdateNoteTool;
use App\Mcp\Tools\Opportunity\CreateOpportunityTool;
use App\Mcp\Tools\Opportunity\UpdateOpportunityTool;
use App\Mcp\Tools\People\CreatePeopleTool;
use App\Mcp\Tools\People\UpdatePeopleTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

// ---------------------------------------------------------------------------
// ListTasksTool: assigned_to_me filter
// ---------------------------------------------------------------------------
describe('ListTasksTool assigned_to_me', function () {
    it('filters tasks assigned to the current user', function (): void {
        $assignedTask = Task::factory()->for($this->team)->create(['title' => 'Assigned Task']);
        $assignedTask->assignees()->attach($this->user);

        $unassignedTask = Task::factory()->for($this->team)->create(['title' => 'Unassigned Task']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListTasksTool::class, [
                'assigned_to_me' => true,
            ])
            ->assertOk()
            ->assertSee('Assigned Task')
            ->assertDontSee('Unassigned Task');
    });

    it('returns all tasks when assigned_to_me is not set', function (): void {
        $assignedTask = Task::factory()->for($this->team)->create(['title' => 'Assigned Task']);
        $assignedTask->assignees()->attach($this->user);

        Task::factory()->for($this->team)->create(['title' => 'Unassigned Task']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListTasksTool::class)
            ->assertOk()
            ->assertSee('Assigned Task')
            ->assertSee('Unassigned Task');
    });
});

// ---------------------------------------------------------------------------
// ListNotesTool: notable_type / notable_id filtering
// ---------------------------------------------------------------------------
describe('ListNotesTool notable filtering', function () {
    it('filters notes by notable_type company', function (): void {
        $company = Company::factory()->for($this->team)->create();
        $person = People::factory()->for($this->team)->create();

        $companyNote = Note::factory()->for($this->team)->create(['title' => 'Company Note']);
        $companyNote->companies()->attach($company);

        $personNote = Note::factory()->for($this->team)->create(['title' => 'Person Note']);
        $personNote->people()->attach($person);

        RelaticleServer::actingAs($this->user)
            ->tool(ListNotesTool::class, [
                'notable_type' => 'company',
            ])
            ->assertOk()
            ->assertSee('Company Note')
            ->assertDontSee('Person Note');
    });

    it('filters notes by notable_type people', function (): void {
        $company = Company::factory()->for($this->team)->create();
        $person = People::factory()->for($this->team)->create();

        $companyNote = Note::factory()->for($this->team)->create(['title' => 'Company Note']);
        $companyNote->companies()->attach($company);

        $personNote = Note::factory()->for($this->team)->create(['title' => 'Person Note']);
        $personNote->people()->attach($person);

        RelaticleServer::actingAs($this->user)
            ->tool(ListNotesTool::class, [
                'notable_type' => 'people',
            ])
            ->assertOk()
            ->assertSee('Person Note')
            ->assertDontSee('Company Note');
    });

    it('filters notes by notable_id', function (): void {
        $company1 = Company::factory()->for($this->team)->create();
        $company2 = Company::factory()->for($this->team)->create();

        $note1 = Note::factory()->for($this->team)->create(['title' => 'Note For Company 1']);
        $note1->companies()->attach($company1);

        $note2 = Note::factory()->for($this->team)->create(['title' => 'Note For Company 2']);
        $note2->companies()->attach($company2);

        RelaticleServer::actingAs($this->user)
            ->tool(ListNotesTool::class, [
                'notable_id' => $company1->id,
            ])
            ->assertOk()
            ->assertSee('Note For Company 1')
            ->assertDontSee('Note For Company 2');
    });

    it('filters notes by notable_type and notable_id combined', function (): void {
        $company = Company::factory()->for($this->team)->create();
        $opportunity = Opportunity::factory()->for($this->team)->create();

        $companyNote = Note::factory()->for($this->team)->create(['title' => 'Specific Company Note']);
        $companyNote->companies()->attach($company);

        $opportunityNote = Note::factory()->for($this->team)->create(['title' => 'Opportunity Note']);
        $opportunityNote->opportunities()->attach($opportunity);

        RelaticleServer::actingAs($this->user)
            ->tool(ListNotesTool::class, [
                'notable_type' => 'company',
                'notable_id' => $company->id,
            ])
            ->assertOk()
            ->assertSee('Specific Company Note')
            ->assertDontSee('Opportunity Note');
    });
});

// ---------------------------------------------------------------------------
// creation_source = MCP for all entities
// ---------------------------------------------------------------------------
describe('creation_source is MCP', function () {
    it('sets creation_source to MCP for companies', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, ['name' => 'MCP Source Company'])
            ->assertOk();

        $company = Company::query()->where('name', 'MCP Source Company')->first();
        expect($company->creation_source)->toBe(CreationSource::MCP);
    });

    it('sets creation_source to MCP for people', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreatePeopleTool::class, ['name' => 'MCP Source Person'])
            ->assertOk();

        $person = People::query()->where('name', 'MCP Source Person')->first();
        expect($person->creation_source)->toBe(CreationSource::MCP);
    });

    it('sets creation_source to MCP for opportunities', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, ['name' => 'MCP Source Deal'])
            ->assertOk();

        $opportunity = Opportunity::query()->where('name', 'MCP Source Deal')->first();
        expect($opportunity->creation_source)->toBe(CreationSource::MCP);
    });

    it('sets creation_source to MCP for tasks', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateTaskTool::class, ['title' => 'MCP Source Task'])
            ->assertOk();

        $task = Task::query()->where('title', 'MCP Source Task')->first();
        expect($task->creation_source)->toBe(CreationSource::MCP);
    });

    it('sets creation_source to MCP for notes', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'MCP Source Note'])
            ->assertOk();

        $note = Note::query()->where('title', 'MCP Source Note')->first();
        expect($note->creation_source)->toBe(CreationSource::MCP);
    });
});

// ---------------------------------------------------------------------------
// Required-field validation for all entities
// ---------------------------------------------------------------------------
describe('required-field validation', function () {
    it('rejects empty name on company create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [])
            ->assertHasErrors(['name']);
    });

    it('rejects empty name on people create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreatePeopleTool::class, [])
            ->assertHasErrors(['name']);
    });

    it('rejects empty name on opportunity create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, [])
            ->assertHasErrors(['name']);
    });

    it('rejects empty title on task create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateTaskTool::class, [])
            ->assertHasErrors(['title']);
    });

    it('rejects empty title on note create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, [])
            ->assertHasErrors(['title']);
    });

    it('rejects name exceeding 255 characters for people', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreatePeopleTool::class, ['name' => str_repeat('a', 256)])
            ->assertHasErrors(['name']);
    });

    it('rejects name exceeding 255 characters for opportunity', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, ['name' => str_repeat('a', 256)])
            ->assertHasErrors(['name']);
    });

    it('rejects title exceeding 255 characters for task', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateTaskTool::class, ['title' => str_repeat('a', 256)])
            ->assertHasErrors(['title']);
    });

    it('rejects title exceeding 255 characters for note', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => str_repeat('a', 256)])
            ->assertHasErrors(['title']);
    });
});

// ---------------------------------------------------------------------------
// Custom field create/update tests for all entities
// ---------------------------------------------------------------------------
describe('custom field create via MCP', function () {
    beforeEach(function () {
        $entityTypes = ['company', 'people', 'opportunity', 'task', 'note'];

        foreach ($entityTypes as $entityType) {
            $section = CustomFieldSection::create([
                'tenant_id' => $this->team->id,
                'entity_type' => $entityType,
                'name' => "MCP Test Section for {$entityType}",
                'code' => "mcp_test_{$entityType}",
                'type' => 'section',
                'sort_order' => 1,
                'active' => true,
            ]);

            CustomField::create([
                'tenant_id' => $this->team->id,
                'custom_field_section_id' => $section->id,
                'entity_type' => $entityType,
                'code' => 'cf_website',
                'name' => 'Website',
                'type' => 'text',
                'sort_order' => 1,
                'active' => true,
                'validation_rules' => [],
            ]);
        }
    });

    it('creates company with custom fields', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => 'CF Test Company',
                'custom_fields' => ['cf_website' => 'https://example.com'],
            ])
            ->assertOk()
            ->assertSee('CF Test Company');

        $company = Company::query()->where('name', 'CF Test Company')->first();
        expect($company)->not->toBeNull();
    });

    it('creates person with custom fields', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreatePeopleTool::class, [
                'name' => 'CF Test Person',
                'custom_fields' => ['cf_website' => 'https://example.com'],
            ])
            ->assertOk()
            ->assertSee('CF Test Person');

        $person = People::query()->where('name', 'CF Test Person')->first();
        expect($person)->not->toBeNull();
    });

    it('creates opportunity with custom fields', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, [
                'name' => 'CF Test Deal',
                'custom_fields' => ['cf_website' => 'https://example.com'],
            ])
            ->assertOk()
            ->assertSee('CF Test Deal');

        $opportunity = Opportunity::query()->where('name', 'CF Test Deal')->first();
        expect($opportunity)->not->toBeNull();
    });

    it('creates task with custom fields', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateTaskTool::class, [
                'title' => 'CF Test Task',
                'custom_fields' => ['cf_website' => 'https://example.com'],
            ])
            ->assertOk()
            ->assertSee('CF Test Task');

        $task = Task::query()->where('title', 'CF Test Task')->first();
        expect($task)->not->toBeNull();
    });

    it('creates note with custom fields', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, [
                'title' => 'CF Test Note',
                'custom_fields' => ['cf_website' => 'https://example.com'],
            ])
            ->assertOk()
            ->assertSee('CF Test Note');

        $note = Note::query()->where('title', 'CF Test Note')->first();
        expect($note)->not->toBeNull();
    });
});

describe('custom field update via MCP', function () {
    beforeEach(function () {
        $entityTypes = ['company', 'people', 'opportunity', 'task', 'note'];

        foreach ($entityTypes as $entityType) {
            $section = CustomFieldSection::create([
                'tenant_id' => $this->team->id,
                'entity_type' => $entityType,
                'name' => "MCP Update Section for {$entityType}",
                'code' => "mcp_update_{$entityType}",
                'type' => 'section',
                'sort_order' => 1,
                'active' => true,
            ]);

            CustomField::create([
                'tenant_id' => $this->team->id,
                'custom_field_section_id' => $section->id,
                'entity_type' => $entityType,
                'code' => 'cf_notes',
                'name' => 'Notes',
                'type' => 'text',
                'sort_order' => 1,
                'active' => true,
                'validation_rules' => [],
            ]);
        }
    });

    it('updates company with custom fields', function (): void {
        $company = Company::factory()->for($this->team)->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateCompanyTool::class, [
                'id' => $company->id,
                'custom_fields' => ['cf_notes' => 'Updated via MCP'],
            ])
            ->assertOk();
    });

    it('updates person with custom fields', function (): void {
        $person = People::factory()->for($this->team)->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdatePeopleTool::class, [
                'id' => $person->id,
                'custom_fields' => ['cf_notes' => 'Updated via MCP'],
            ])
            ->assertOk();
    });

    it('updates opportunity with custom fields', function (): void {
        $opportunity = Opportunity::factory()->for($this->team)->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateOpportunityTool::class, [
                'id' => $opportunity->id,
                'custom_fields' => ['cf_notes' => 'Updated via MCP'],
            ])
            ->assertOk();
    });

    it('updates task with custom fields', function (): void {
        $task = Task::factory()->for($this->team)->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateTaskTool::class, [
                'id' => $task->id,
                'custom_fields' => ['cf_notes' => 'Updated via MCP'],
            ])
            ->assertOk();
    });

    it('updates note with custom fields', function (): void {
        $note = Note::factory()->for($this->team)->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateNoteTool::class, [
                'id' => $note->id,
                'custom_fields' => ['cf_notes' => 'Updated via MCP'],
            ])
            ->assertOk();
    });
});

// ---------------------------------------------------------------------------
// Unknown custom field key rejection
// ---------------------------------------------------------------------------
describe('unknown custom field key rejection', function () {
    beforeEach(function () {
        $section = CustomFieldSection::create([
            'tenant_id' => $this->team->id,
            'entity_type' => 'company',
            'name' => 'Unknown Key Test Section',
            'code' => 'unknown_key_test',
            'type' => 'section',
            'sort_order' => 1,
            'active' => true,
        ]);

        CustomField::create([
            'tenant_id' => $this->team->id,
            'custom_field_section_id' => $section->id,
            'entity_type' => 'company',
            'code' => 'valid_field',
            'name' => 'Valid Field',
            'type' => 'text',
            'sort_order' => 1,
            'active' => true,
            'validation_rules' => [],
        ]);
    });

    it('rejects unknown custom field key on create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => 'Unknown Key Company',
                'custom_fields' => ['jobTitle' => 'VP of Engineering'],
            ])
            ->assertHasErrors(['Unknown custom field keys: jobTitle']);
    });

    it('rejects unknown custom field key on update', function (): void {
        $company = Company::factory()->for($this->team)->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateCompanyTool::class, [
                'id' => $company->id,
                'custom_fields' => ['nonexistent' => 'some value'],
            ])
            ->assertHasErrors(['Unknown custom field keys: nonexistent']);
    });

    it('rejects unknown key while accepting valid key in same request', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => 'Mixed Keys Company',
                'custom_fields' => [
                    'valid_field' => 'good value',
                    'invalid_field' => 'bad value',
                ],
            ])
            ->assertHasErrors(['Unknown custom field keys: invalid_field']);
    });
});

// ---------------------------------------------------------------------------
// Custom field validation rejection tests (invalid type: string to number field)
// ---------------------------------------------------------------------------
describe('custom field validation rejection', function () {
    beforeEach(function () {
        $entityTypes = ['company', 'people', 'opportunity', 'task', 'note'];

        foreach ($entityTypes as $entityType) {
            $section = CustomFieldSection::create([
                'tenant_id' => $this->team->id,
                'entity_type' => $entityType,
                'name' => "Number Section for {$entityType}",
                'code' => "number_{$entityType}",
                'type' => 'section',
                'sort_order' => 1,
                'active' => true,
            ]);

            CustomField::create([
                'tenant_id' => $this->team->id,
                'custom_field_section_id' => $section->id,
                'entity_type' => $entityType,
                'code' => 'cf_amount',
                'name' => 'Amount',
                'type' => 'number',
                'sort_order' => 1,
                'active' => true,
                'validation_rules' => [],
            ]);
        }
    });

    it('rejects non-numeric custom field value on company create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => 'Bad CF Company',
                'custom_fields' => ['cf_amount' => 'not_a_number'],
            ])
            ->assertHasErrors(['cf amount']);
    });

    it('rejects non-numeric custom field value on people create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreatePeopleTool::class, [
                'name' => 'Bad CF Person',
                'custom_fields' => ['cf_amount' => 'not_a_number'],
            ])
            ->assertHasErrors(['cf amount']);
    });

    it('rejects non-numeric custom field value on opportunity create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, [
                'name' => 'Bad CF Deal',
                'custom_fields' => ['cf_amount' => 'not_a_number'],
            ])
            ->assertHasErrors(['cf amount']);
    });

    it('rejects non-numeric custom field value on task create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateTaskTool::class, [
                'title' => 'Bad CF Task',
                'custom_fields' => ['cf_amount' => 'not_a_number'],
            ])
            ->assertHasErrors(['cf amount']);
    });

    it('rejects non-numeric custom field value on note create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, [
                'title' => 'Bad CF Note',
                'custom_fields' => ['cf_amount' => 'not_a_number'],
            ])
            ->assertHasErrors(['cf amount']);
    });
});
