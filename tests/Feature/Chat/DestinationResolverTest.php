<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Support\DestinationResolver;

mutates(DestinationResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

it('resolves the custom fields destination to an app-panel url', function (): void {
    $url = app(DestinationResolver::class)->resolve('custom_fields', $this->user->currentTeam);

    expect($url)->toBeString()
        ->and($url)->toContain((string) $this->user->currentTeam->slug)
        ->and($url)->toContain('custom-fields');
});

it('resolves every declared destination to a non-null url', function (): void {
    $resolver = app(DestinationResolver::class);

    foreach (DestinationResolver::DESTINATIONS as $destination) {
        expect($resolver->resolve($destination, $this->user->currentTeam))
            ->toBeString("destination [{$destination}] should resolve to a url");
    }
});

it('returns null for an unknown destination', function (): void {
    expect(app(DestinationResolver::class)->resolve('does_not_exist', $this->user->currentTeam))
        ->toBeNull();
});
