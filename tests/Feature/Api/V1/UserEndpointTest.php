<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function (): void {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});

it('returns user resource with correct shape', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->whereType('id', 'string')
                ->where('type', 'users')
                ->has('attributes', fn (AssertableJson $json) => $json
                    ->where('name', 'Jane Doe')
                    ->where('email', 'jane@example.com')
                    ->missing('password')
                    ->missing('remember_token')
                    ->missing('two_factor_secret')
                    ->missing('two_factor_recovery_codes')
                    ->missing('current_team_id')
                    ->missing('email_verified_at')
                    ->missing('profile_photo_path')
                    ->missing('profile_photo_url')
                    ->missing('created_at')
                    ->missing('updated_at')
                )
            )
        );
});

it('does not expose internal fields in response', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/user')->assertOk();

    $attributes = $response->json('data.attributes');

    expect(array_keys($attributes))->toBe(['name', 'email']);
});
