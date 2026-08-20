<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
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
    // The List*Tool cases below assert over every row the tool returns, so demo
    // records are part of what they cover. Keep seeding on here rather than let
    // those loops quietly shrink to the handful of rows each test creates.
    Feature::define(OnboardSeed::class, true);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
    // Deliberately no Filament::setTenant() — mirrors job context
});

// --- GetCompanyTool ---

it('GetCompanyTool output url is the /r/company/{id} reference url', function (): void {
    $company = Company::factory()->for($this->user->currentTeam)->create(['name' => 'Acme']);

    $payload = json_decode(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBe("/r/company/{$company->getKey()}");
});

// --- ListCompaniesTool ---

it('ListCompaniesTool output items each have a /r/company/{id} reference url', function (): void {
    Company::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBe("/r/company/{$item['id']}");
    }
});

// --- GetPersonTool ---

it('GetPersonTool output url is the /r/people/{id} reference url', function (): void {
    $person = People::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetPersonTool::class)->handle(new Request(['id' => (string) $person->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBe("/r/people/{$person->getKey()}");
});

// --- ListPeopleTool ---

it('ListPeopleTool output items each have a /r/people/{id} reference url', function (): void {
    People::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListPeopleTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBe("/r/people/{$item['id']}");
    }
});

// --- GetOpportunityTool ---

it('GetOpportunityTool output url is the /r/opportunity/{id} reference url', function (): void {
    $opportunity = Opportunity::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetOpportunityTool::class)->handle(new Request(['id' => (string) $opportunity->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBe("/r/opportunity/{$opportunity->getKey()}");
});

// --- ListOpportunitiesTool ---

it('ListOpportunitiesTool output items each have a /r/opportunity/{id} reference url', function (): void {
    Opportunity::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListOpportunitiesTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBe("/r/opportunity/{$item['id']}");
    }
});

// --- GetTaskTool ---

it('GetTaskTool output url is the /r/task/{id} reference url', function (): void {
    $task = Task::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetTaskTool::class)->handle(new Request(['id' => (string) $task->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBe("/r/task/{$task->getKey()}");
});

// --- ListTasksTool ---

it('ListTasksTool output items each have a /r/task/{id} reference url', function (): void {
    Task::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListTasksTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBe("/r/task/{$item['id']}");
    }
});

// --- GetNoteTool ---

it('GetNoteTool output url is the /r/note/{id} reference url', function (): void {
    $note = Note::factory()->for($this->user->currentTeam)->create();

    $payload = json_decode(app(GetNoteTool::class)->handle(new Request(['id' => (string) $note->getKey()])), true);

    expect($payload)->toHaveKey('url')
        ->and($payload['url'])->toBe("/r/note/{$note->getKey()}");
});

// --- ListNotesTool ---

it('ListNotesTool output items each have a /r/note/{id} reference url', function (): void {
    Note::factory()->count(2)->for($this->user->currentTeam)->create();

    $payload = json_decode(app(ListNotesTool::class)->handle(new Request([])), true);

    expect($payload)->toBeArray()->not->toBeEmpty();

    foreach ($payload as $item) {
        expect($item)->toHaveKey('url')
            ->and($item['url'])->toBe("/r/note/{$item['id']}");
    }
});
