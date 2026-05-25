<?php

declare(strict_types=1);

use App\Models\Team;

mutates(Team::class);

it('rotates the invite link token to a new 40-char string', function (): void {
    $team = Team::factory()->create();
    $original = $team->invite_link_token;

    expect($original)->toBeString()->toHaveLength(40);

    $team->rotateInviteLink();

    expect($team->invite_link_token)
        ->toBeString()
        ->toHaveLength(40)
        ->not->toBe($original);

    $team->refresh();

    expect($team->invite_link_token)->not->toBe($original);
});

it('persists the rotated token immediately', function (): void {
    $team = Team::factory()->create();
    $original = $team->invite_link_token;

    $team->rotateInviteLink();

    $fresh = Team::query()->whereKey($team->id)->first();

    expect($fresh->invite_link_token)
        ->not->toBe($original)
        ->toHaveLength(40);
});
