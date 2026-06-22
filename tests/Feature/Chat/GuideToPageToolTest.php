<?php

declare(strict_types=1);

use App\Models\User;
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
