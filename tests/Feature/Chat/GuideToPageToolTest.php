<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Serializer;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\DestinationResolver;
use Relaticle\Chat\Tools\GuideToPageTool;

mutates(GuideToPageTool::class);
mutates(DestinationResolver::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

it('returns a navigation payload with a url for a known destination', function (): void {
    $json = app(GuideToPageTool::class)->handle(new Request(['destination' => 'custom_fields']));
    $payload = json_decode($json, true);

    expect($payload['type'])->toBe('navigation')
        ->and($payload['destination'])->toBe('custom_fields')
        ->and($payload['url'])->toBeString()
        ->and($payload['url'])->toContain('custom-fields');
});

it('returns an error payload for an unknown destination', function (): void {
    $json = app(GuideToPageTool::class)->handle(new Request(['destination' => 'nope']));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('error')
        ->and($payload)->not->toHaveKey('url');
});

it('does not create a pending action because it is not a write', function (): void {
    app(GuideToPageTool::class)->handle(new Request(['destination' => 'team_members']));

    expect(PendingAction::query()->count())->toBe(0);
});

/**
 * The model picks a destination from the schema description, so a key the
 * resolver knows but the description omits is unreachable. The two lists are
 * written by hand in separate files; this is the only thing stopping drift.
 */
it('advertises every resolvable destination in its schema description', function (): void {
    $schema = app(GuideToPageTool::class)->schema(new JsonSchemaTypeFactory);
    $destination = (new Serializer)->serialize($schema['destination']);

    foreach (DestinationResolver::DESTINATIONS as $key) {
        expect($destination['description'])->toContain($key);
    }
});
