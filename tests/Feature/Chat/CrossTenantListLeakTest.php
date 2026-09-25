<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Note\ListNotesTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;

it('list companies tool does not leak rows from other workspaces', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();

    Company::factory()->for($userA->currentWorkspace)->create(['name' => 'WORKSPACE-A-COMPANY']);
    Company::factory()->for($userB->currentWorkspace)->create(['name' => 'WORKSPACE-B-COMPANY']);

    $this->actingAs($userA);

    $tool = app(ListCompaniesTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('WORKSPACE-A-COMPANY');
    expect($payload)->not->toContain('WORKSPACE-B-COMPANY');
});

it('list people tool does not leak rows from other workspaces', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();

    People::factory()->for($userA->currentWorkspace)->create(['name' => 'WORKSPACE-A-PERSON']);
    People::factory()->for($userB->currentWorkspace)->create(['name' => 'WORKSPACE-B-PERSON']);

    $this->actingAs($userA);

    $tool = app(ListPeopleTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('WORKSPACE-A-PERSON');
    expect($payload)->not->toContain('WORKSPACE-B-PERSON');
});

it('list opportunities tool does not leak rows from other workspaces', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();

    Opportunity::factory()->for($userA->currentWorkspace)->create(['name' => 'WORKSPACE-A-OPPORTUNITY']);
    Opportunity::factory()->for($userB->currentWorkspace)->create(['name' => 'WORKSPACE-B-OPPORTUNITY']);

    $this->actingAs($userA);

    $tool = app(ListOpportunitiesTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('WORKSPACE-A-OPPORTUNITY');
    expect($payload)->not->toContain('WORKSPACE-B-OPPORTUNITY');
});

it('list tasks tool does not leak rows from other workspaces', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();

    Task::factory()->for($userA->currentWorkspace)->create(['title' => 'WORKSPACE-A-TASK']);
    Task::factory()->for($userB->currentWorkspace)->create(['title' => 'WORKSPACE-B-TASK']);

    $this->actingAs($userA);

    $tool = app(ListTasksTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('WORKSPACE-A-TASK');
    expect($payload)->not->toContain('WORKSPACE-B-TASK');
});

it('list notes tool does not leak rows from other workspaces', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();

    Note::factory()->for($userA->currentWorkspace)->create(['title' => 'WORKSPACE-A-NOTE']);
    Note::factory()->for($userB->currentWorkspace)->create(['title' => 'WORKSPACE-B-NOTE']);

    $this->actingAs($userA);

    $tool = app(ListNotesTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('WORKSPACE-A-NOTE');
    expect($payload)->not->toContain('WORKSPACE-B-NOTE');
});

it('list companies tool points open_url at the current workspace panel, never another workspace\'s', function (): void {
    $userA = User::factory()->withPersonalWorkspace()->create();
    $userB = User::factory()->withPersonalWorkspace()->create();
    $workspaceA = $userA->currentWorkspace;
    $workspaceB = $userB->currentWorkspace;

    Company::factory()->count(15)->for($workspaceA)->create();
    Company::factory()->for($workspaceB)->create();

    $this->actingAs($userA);

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload['has_more'])->toBeTrue()
        ->and($payload['display_block']['open_url'])->toBe(CompanyResource::getUrl('index', panel: 'app', tenant: $workspaceA))
        ->and($payload['display_block']['open_url'])->toContain($workspaceA->slug)
        ->and($payload['display_block']['open_url'])->not->toContain($workspaceB->slug);
});
