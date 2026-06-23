<?php

declare(strict_types=1);

use App\Actions\Company\ListCompanies;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\People\ListPeople;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\People\ListPeopleTool;

mutates(ListOpportunities::class, ListCompanies::class, ListPeople::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);
});

it('filters opportunities by created_after', function (): void {
    $this->travelTo(now()->subDays(10));
    Opportunity::factory()->for($this->team)->create(['name' => 'Old Deal']);

    $this->travelBack();
    Opportunity::factory()->for($this->team)->create(['name' => 'New Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request([
        'created_after' => now()->subDays(1)->toDateString(),
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('New Deal');
});

it('filters opportunities by created_before', function (): void {
    $this->travelTo(now()->subDays(10));
    Opportunity::factory()->for($this->team)->create(['name' => 'Old Deal']);

    $this->travelBack();
    Opportunity::factory()->for($this->team)->create(['name' => 'New Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request([
        'created_before' => now()->subDays(5)->toDateString(),
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Old Deal');
});

it('filters opportunities by both created_after and created_before', function (): void {
    $now = now();

    $this->travelTo($now->copy()->subDays(20));
    Opportunity::factory()->for($this->team)->create(['name' => 'Very Old']);

    $this->travelTo($now->copy()->subDays(7));
    Opportunity::factory()->for($this->team)->create(['name' => 'Mid Deal']);

    $this->travelTo($now);
    Opportunity::factory()->for($this->team)->create(['name' => 'Fresh Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request([
        'created_after' => $now->copy()->subDays(14)->toDateString(),
        'created_before' => $now->copy()->subDays(3)->toDateString(),
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Mid Deal');
});

it('filters companies by created_after', function (): void {
    $this->travelTo(now()->subDays(10));
    Company::factory()->for($this->team)->create(['name' => 'Old Co']);

    $this->travelBack();
    Company::factory()->for($this->team)->create(['name' => 'New Co']);

    $tool = new ListCompaniesTool;
    $response = $tool->handle(new Request([
        'created_after' => now()->subDays(1)->toDateString(),
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('New Co');
});

it('filters people by created_after', function (): void {
    $this->travelTo(now()->subDays(10));
    People::factory()->for($this->team)->create(['name' => 'Old Person']);

    $this->travelBack();
    People::factory()->for($this->team)->create(['name' => 'New Person']);

    $tool = new ListPeopleTool;
    $response = $tool->handle(new Request([
        'created_after' => now()->subDays(1)->toDateString(),
    ]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('New Person');
});
