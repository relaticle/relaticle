<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passport\Passport;

it('persists an OAuth client owned by a ULID user', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $client = Passport::clientModel()::create([
        'name' => 'Test Directory Client',
        'redirect_uris' => json_encode(['https://example.com/callback']),
        'grant_types' => json_encode(['authorization_code', 'refresh_token']),
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    expect($client->fresh())
        ->not->toBeNull()
        ->owner_id->toBe($user->getKey())
        ->owner_type->toBe($user->getMorphClass());

    expect($user->oauthApps()->count())->toBe(1);
});
