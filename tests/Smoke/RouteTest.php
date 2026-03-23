<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

use function Spatie\RouteTesting\routeTesting;

TestResponse::macro('assertNotServerError', function (): TestResponse {
    /** @var TestResponse $this */
    Assert::assertLessThan(
        500,
        $this->getStatusCode(),
        "Response returned a server error [{$this->getStatusCode()}]."
    );

    return $this;
});

routeTesting('smoke: all GET routes return non-500 response')
    ->setUp(function (): void {
        $user = User::factory()->withTeam()->create();

        $this->actingAs($user);
    })
    ->ignoreRoutesWithMissingBindings()
    ->exclude([
        '__clockwork*',
        'clockwork*',
        'livewire-*',
        'sanctum/csrf-cookie',
        'auth/redirect/*',
        'auth/callback/*',
        'email/verify/*',
        'email-verification/verify/*',
        'filament/exports/*/download',
        'filament/imports/*/failed-rows/download',
        'team-invitations/*',
        'password-reset/*',
        'reset-password/*',
        'discord',
        'user/confirm-password',
        'up',
        'docs/api*',
    ])
    ->assertNotServerError();
