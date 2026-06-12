<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Relaticle\Chat\Http\Controllers\ChatController;

mutates(ChatController::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);
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
    Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);
    Company::factory()->for($this->team)->create(['name' => 'Globex']);

    $response = $this->getJson(route('chat.mentions', ['q' => '%%']))
        ->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('returns matching companies for current team only', function (): void {
    Company::factory()->for($this->team)->create(['name' => 'Acme Inc']);

    $otherUser = User::factory()->withPersonalTeam()->create();
    Company::factory()->for($otherUser->currentTeam)->create(['name' => 'Acme Corp']);

    $response = $this->getJson(route('chat.mentions', ['q' => 'Acme']))
        ->assertOk();

    $data = collect($response->json('data'));
    $companies = $data->where('type', 'company');

    expect($companies)->toHaveCount(1)
        ->and($companies->first()['name'])->toBe('Acme Inc');
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
