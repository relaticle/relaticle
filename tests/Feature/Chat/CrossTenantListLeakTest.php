<?php

declare(strict_types=1);

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

it('list companies tool does not leak rows from other teams', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $userB = User::factory()->withPersonalTeam()->create();

    Company::factory()->for($userA->currentTeam)->create(['name' => 'TEAM-A-COMPANY']);
    Company::factory()->for($userB->currentTeam)->create(['name' => 'TEAM-B-COMPANY']);

    $this->actingAs($userA);

    $tool = app(ListCompaniesTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('TEAM-A-COMPANY');
    expect($payload)->not->toContain('TEAM-B-COMPANY');
});

it('list people tool does not leak rows from other teams', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $userB = User::factory()->withPersonalTeam()->create();

    People::factory()->for($userA->currentTeam)->create(['name' => 'TEAM-A-PERSON']);
    People::factory()->for($userB->currentTeam)->create(['name' => 'TEAM-B-PERSON']);

    $this->actingAs($userA);

    $tool = app(ListPeopleTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('TEAM-A-PERSON');
    expect($payload)->not->toContain('TEAM-B-PERSON');
});

it('list opportunities tool does not leak rows from other teams', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $userB = User::factory()->withPersonalTeam()->create();

    Opportunity::factory()->for($userA->currentTeam)->create(['name' => 'TEAM-A-OPPORTUNITY']);
    Opportunity::factory()->for($userB->currentTeam)->create(['name' => 'TEAM-B-OPPORTUNITY']);

    $this->actingAs($userA);

    $tool = app(ListOpportunitiesTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('TEAM-A-OPPORTUNITY');
    expect($payload)->not->toContain('TEAM-B-OPPORTUNITY');
});

it('list tasks tool does not leak rows from other teams', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $userB = User::factory()->withPersonalTeam()->create();

    Task::factory()->for($userA->currentTeam)->create(['title' => 'TEAM-A-TASK']);
    Task::factory()->for($userB->currentTeam)->create(['title' => 'TEAM-B-TASK']);

    $this->actingAs($userA);

    $tool = app(ListTasksTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('TEAM-A-TASK');
    expect($payload)->not->toContain('TEAM-B-TASK');
});

it('list notes tool does not leak rows from other teams', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $userB = User::factory()->withPersonalTeam()->create();

    Note::factory()->for($userA->currentTeam)->create(['title' => 'TEAM-A-NOTE']);
    Note::factory()->for($userB->currentTeam)->create(['title' => 'TEAM-B-NOTE']);

    $this->actingAs($userA);

    $tool = app(ListNotesTool::class);
    $payload = $tool->handle(new Request([]));

    expect($payload)->toContain('TEAM-A-NOTE');
    expect($payload)->not->toContain('TEAM-B-NOTE');
});
