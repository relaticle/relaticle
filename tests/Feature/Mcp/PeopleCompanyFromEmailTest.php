<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\People\CreatePeopleTool;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(CreatePeopleTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->personalTeam();
    TenantContextService::setTenantId($this->team->getKey());
    $this->team->update(['auto_create_companies' => true]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
    People::clearBootedModels();
});

it('creates and links a company when a person is created via MCP with a work email', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreatePeopleTool::class, [
            'name' => 'MCP Jane',
            'custom_fields' => ['emails' => ['jane@mcp-acme.com']],
        ])
        ->assertOk();

    $person = People::query()->where('team_id', $this->team->id)->where('name', 'MCP Jane')->firstOrFail();

    expect($person->company_id)->not->toBeNull();
    expect(Company::query()->where('team_id', $this->team->id)->where('name', 'Mcp-acme')->exists())->toBeTrue();
});
