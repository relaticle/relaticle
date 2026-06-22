<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\GetCompanyTool;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Note\GetNoteTool;
use Relaticle\Chat\Tools\Note\ListNotesTool;
use Relaticle\Chat\Tools\Opportunity\GetOpportunityTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\GetPersonTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;
use Relaticle\Chat\Tools\Task\GetTaskTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;

mutates(GetCompanyTool::class);
mutates(ListCompaniesTool::class);
mutates(GetPersonTool::class);
mutates(ListPeopleTool::class);
mutates(GetOpportunityTool::class);
mutates(ListOpportunitiesTool::class);
mutates(GetTaskTool::class);
mutates(ListTasksTool::class);
mutates(GetNoteTool::class);
mutates(ListNotesTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
    // Deliberately no Filament::setTenant() — mirrors job context
});

// --- GetCompanyTool ---

it('GetCompanyTool output contains a non-null url with /companies/', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Acme']);

    $payload = json_decode(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBeString()
        ->and($payload['url'])->toContain('/companies/');
});

// --- ListCompaniesTool ---

it('ListCompaniesTool output items each have a url containing /companies/', function (): void {
    Company::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBeString()
            ->and($item['url'])->toContain('/companies/');
    }
});

// --- GetPersonTool ---

it('GetPersonTool output contains a url with /people/', function (): void {
    $person = People::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetPersonTool::class)->handle(new Request(['id' => (string) $person->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toContain('/people/');
});

// --- ListPeopleTool ---

it('ListPeopleTool output items each have a url with /people/', function (): void {
    People::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListPeopleTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toContain('/people/');
    }
});

// --- GetOpportunityTool ---

it('GetOpportunityTool output contains a url with /opportunities/', function (): void {
    $opportunity = Opportunity::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetOpportunityTool::class)->handle(new Request(['id' => (string) $opportunity->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toContain('/opportunities/');
});

// --- ListOpportunitiesTool ---

it('ListOpportunitiesTool output items each have a url with /opportunities/', function (): void {
    Opportunity::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListOpportunitiesTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toContain('/opportunities/');
    }
});

// --- GetTaskTool ---

it('GetTaskTool output contains a url (task index)', function (): void {
    $task = Task::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetTaskTool::class)->handle(new Request(['id' => (string) $task->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBeString()
        ->and($payload['url'])->toContain('/tasks');
});

// --- ListTasksTool ---

it('ListTasksTool output items each have a url (task index)', function (): void {
    Task::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListTasksTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBeString()
            ->and($item['url'])->toContain('/tasks');
    }
});

// --- GetNoteTool ---

it('GetNoteTool output contains a url (note index)', function (): void {
    $note = Note::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetNoteTool::class)->handle(new Request(['id' => (string) $note->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBeString()
        ->and($payload['url'])->toContain('/notes');
});

// --- ListNotesTool ---

it('ListNotesTool output items each have a url (note index)', function (): void {
    Note::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListNotesTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBeString()
            ->and($item['url'])->toContain('/notes');
    }
});
