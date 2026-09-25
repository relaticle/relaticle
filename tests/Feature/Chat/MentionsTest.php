<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Relaticle\Chat\Http\Controllers\ChatController;

mutates(ChatController::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    RateLimiter::clear('60|'.request()->ip());
});

it('requires the q parameter', function (): void {
    $this->getJson(route('chat.mentions'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

it('rejects q longer than 100 characters', function (): void {
    $this->getJson(route('chat.mentions', ['q' => str_repeat('a', 101)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

it('rejects q shorter than 2 characters', function (): void {
    $this->getJson(route('chat.mentions', ['q' => 'a']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('q');
});

it('does not treat % as a LIKE wildcard', function (): void {
    Company::factory()->for($this->workspace)->create(['name' => 'Acme Inc']);
    Company::factory()->for($this->workspace)->create(['name' => 'Globex']);

    $response = $this->getJson(route('chat.mentions', ['q' => '%%']))
        ->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('returns matching companies for current workspace only', function (): void {
    Company::factory()->for($this->workspace)->create(['name' => 'Acme Inc']);

    $otherUser = User::factory()->withPersonalWorkspace()->create();
    Company::factory()->for($otherUser->currentWorkspace)->create(['name' => 'Acme Corp']);

    $response = $this->getJson(route('chat.mentions', ['q' => 'Acme']))
        ->assertOk();

    $data = collect($response->json('data'));
    $companies = $data->where('type', 'company');

    expect($companies)->toHaveCount(1)
        ->and($companies->first()['name'])->toBe('Acme Inc');
});

it('includes a resolved record url for each result', function (): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Inc']);

    $data = collect(
        $this->getJson(route('chat.mentions', ['q' => 'Acme']))
            ->assertOk()
            ->json('data')
    );

    $match = $data->firstWhere('type', 'company');

    expect($match)->not->toBeNull()
        ->and($match['url'])->toBeString()
        ->and($match['url'])->toContain((string) $company->id);
});

it('applies rate limiting', function (): void {
    for ($i = 0; $i < 60; $i++) {
        $response = $this->getJson(route('chat.mentions', ['q' => 'test']));
        if ($response->status() === 429) {
            expect(true)->toBeTrue();

            return;
        }
    }

    $this->getJson(route('chat.mentions', ['q' => 'test']))
        ->assertStatus(429);
});
