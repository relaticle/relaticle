<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Relaticle\Chat\Http\Controllers\ChatController;

mutates(ChatController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    RateLimiter::clear('60|'.request()->ip());
});

it('matches multi-word company names', function (): void {
    Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);
    Company::factory()->for($this->workspace)->create(['name' => 'Acme Industries']);
    Company::factory()->for($this->workspace)->create(['name' => 'Globex']);

    $response = $this->getJson(route('chat.mentions', ['q' => 'Acme C']))->assertOk();

    $names = collect($response->json('data'))->where('type', 'company')->pluck('name');
    expect($names)->toContain('Acme Corp');
    expect($names)->not->toContain('Globex');
});

it('matches multi-word person names', function (): void {
    People::factory()->for($this->workspace)->create(['name' => 'Sarah Chen']);
    People::factory()->for($this->workspace)->create(['name' => 'Sarah Wright']);

    $response = $this->getJson(route('chat.mentions', ['q' => 'Sarah Ch']))->assertOk();

    $names = collect($response->json('data'))->where('type', 'people')->pluck('name');
    expect($names->all())->toBe(['Sarah Chen']);
});
