<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Queries\Companies\CompaniesQuery;
use App\Queries\Concerns\ListsEntity;
use App\Queries\Opportunities\OpportunitiesQuery;
use App\Queries\People\PeopleQuery;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;

mutates(OpportunitiesQuery::class, CompaniesQuery::class, PeopleQuery::class, ListsEntity::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    Auth::guard('web')->setUser($this->user);
});

it('filters opportunities created on or after a date', function (): void {
    $this->travelTo(now()->subDays(10));
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Old Deal']);

    $this->travelBack();
    Opportunity::factory()->for($this->workspace)->create(['name' => 'New Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request([
        'filter' => ['created_at' => ['$gte' => now()->subDays(1)->toDateString()]],
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('New Deal');
});

it('filters opportunities created on or before a date', function (): void {
    $this->travelTo(now()->subDays(10));
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Old Deal']);

    $this->travelBack();
    Opportunity::factory()->for($this->workspace)->create(['name' => 'New Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request([
        'filter' => ['created_at' => ['$lte' => now()->subDays(5)->toDateString()]],
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Old Deal');
});

it('filters opportunities created within a date range', function (): void {
    $now = now();

    $this->travelTo($now->copy()->subDays(20));
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Very Old']);

    $this->travelTo($now->copy()->subDays(7));
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Mid Deal']);

    $this->travelTo($now);
    Opportunity::factory()->for($this->workspace)->create(['name' => 'Fresh Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request([
        'filter' => ['created_at' => [
            '$gte' => $now->copy()->subDays(14)->toDateString(),
            '$lte' => $now->copy()->subDays(3)->toDateString(),
        ]],
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Mid Deal');
});

it('filters companies created on or after a date', function (): void {
    $this->travelTo(now()->subDays(10));
    Company::factory()->for($this->workspace)->create(['name' => 'Old Co']);

    $this->travelBack();
    Company::factory()->for($this->workspace)->create(['name' => 'New Co']);

    $tool = new ListCompaniesTool;
    $response = $tool->handle(new Request([
        'filter' => ['created_at' => ['$gte' => now()->subDays(1)->toDateString()]],
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('New Co');
});

it('filters people created on or after a date', function (): void {
    $this->travelTo(now()->subDays(10));
    People::factory()->for($this->workspace)->create(['name' => 'Old Person']);

    $this->travelBack();
    People::factory()->for($this->workspace)->create(['name' => 'New Person']);

    $tool = new ListPeopleTool;
    $response = $tool->handle(new Request([
        'filter' => ['created_at' => ['$gte' => now()->subDays(1)->toDateString()]],
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('New Person');
});
