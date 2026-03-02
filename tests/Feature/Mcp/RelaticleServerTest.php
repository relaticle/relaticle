<?php

declare(strict_types=1);

use App\Mcp\Prompts\CrmOverviewPrompt;
use App\Mcp\Resources\CompanySchemaResource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Mcp\Tools\Note\CreateNoteTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Opportunity\CreateOpportunityTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\CreatePeopleTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Mcp\Tools\People\UpdatePeopleTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Models\Company;
use App\Models\People;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('can list companies via MCP tool', function (): void {
    Company::factory(3)->for($this->team)->create();

    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListCompaniesTool::class);

    $response->assertOk();
});

it('can create a company via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreateCompanyTool::class, [
            'name' => 'MCP Test Corp',
        ]);

    $response->assertOk()
        ->assertSee('MCP Test Corp');

    $this->assertDatabaseHas('companies', [
        'name' => 'MCP Test Corp',
        'team_id' => $this->team->id,
    ]);
});

it('can update a company via MCP tool', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Old Name']);

    $response = RelaticleServer::actingAs($this->user)
        ->tool(UpdateCompanyTool::class, [
            'id' => $company->id,
            'name' => 'New Name',
        ]);

    $response->assertOk()
        ->assertSee('New Name');

    expect($company->refresh()->name)->toBe('New Name');
});

it('can delete a company via MCP tool', function (): void {
    $company = Company::factory()->for($this->team)->create();

    $response = RelaticleServer::actingAs($this->user)
        ->tool(DeleteCompanyTool::class, [
            'id' => $company->id,
        ]);

    $response->assertOk()
        ->assertSee('has been deleted');

    expect($company->refresh()->trashed())->toBeTrue();
});

it('can list people via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListPeopleTool::class);

    $response->assertOk();
});

it('can create a person via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreatePeopleTool::class, [
            'name' => 'John Doe',
        ]);

    $response->assertOk()
        ->assertSee('John Doe');
});

it('can list opportunities via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class);

    $response->assertOk();
});

it('can create an opportunity via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreateOpportunityTool::class, [
            'name' => 'Big Deal',
        ]);

    $response->assertOk()
        ->assertSee('Big Deal');
});

it('can list tasks via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListTasksTool::class);

    $response->assertOk();
});

it('can create a task via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, [
            'title' => 'Follow up call',
        ]);

    $response->assertOk()
        ->assertSee('Follow up call');
});

it('can list notes via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListNotesTool::class);

    $response->assertOk();
});

it('can create a note via MCP tool', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreateNoteTool::class, [
            'title' => 'Meeting notes',
        ]);

    $response->assertOk()
        ->assertSee('Meeting notes');
});

it('can read the company schema resource', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->resource(CompanySchemaResource::class);

    $response->assertOk()
        ->assertSee('company')
        ->assertSee('custom_fields');
});

it('validates company_id exists when creating a person via MCP', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreatePeopleTool::class, [
            'name' => 'Test Person',
            'company_id' => 'non-existent-id',
        ]);

    $response->assertHasErrors(['company id']);
});

it('validates company_id exists when updating a person via MCP', function (): void {
    $person = People::factory()->for($this->team)->create();

    $response = RelaticleServer::actingAs($this->user)
        ->tool(UpdatePeopleTool::class, [
            'id' => $person->id,
            'company_id' => 'non-existent-id',
        ]);

    $response->assertHasErrors(['company id']);
});

it('validates company_id and contact_id exist when creating opportunity via MCP', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->tool(CreateOpportunityTool::class, [
            'name' => 'Test Deal',
            'company_id' => 'non-existent-id',
            'contact_id' => 'non-existent-id',
        ]);

    $response->assertHasErrors(['company id', 'contact id']);
});

it('can filter companies by name via MCP search param', function (): void {
    Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
    Company::factory()->for($this->team)->create(['name' => 'Beta Inc']);

    $response = RelaticleServer::actingAs($this->user)
        ->tool(ListCompaniesTool::class, [
            'search' => 'Acme',
        ]);

    $response->assertOk()
        ->assertSee('Acme Corp')
        ->assertDontSee('Beta Inc');
});

it('can read the CRM overview prompt', function (): void {
    $response = RelaticleServer::actingAs($this->user)
        ->prompt(CrmOverviewPrompt::class);

    $response->assertOk()
        ->assertSee('CRM Overview');
});

it('throws when updating non-existent company', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(UpdateCompanyTool::class, [
            'id' => 'non-existent-id',
            'name' => 'Ghost',
        ]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

it('throws when deleting non-existent company', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(DeleteCompanyTool::class, [
            'id' => 'non-existent-id',
        ]);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
