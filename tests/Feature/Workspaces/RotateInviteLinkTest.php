<?php

declare(strict_types=1);

use App\Models\Workspace;

mutates(Workspace::class);

it('rotates the invite link token to a new 40-char string', function (): void {
    $workspace = Workspace::factory()->create();
    $original = $workspace->invite_link_token;

    expect($original)->toBeString()->toHaveLength(40);

    $workspace->rotateInviteLink();

    expect($workspace->invite_link_token)
        ->toBeString()
        ->toHaveLength(40)
        ->not->toBe($original);

    $workspace->refresh();

    expect($workspace->invite_link_token)->not->toBe($original);
});

it('persists the rotated token immediately', function (): void {
    $workspace = Workspace::factory()->create();
    $original = $workspace->invite_link_token;

    $workspace->rotateInviteLink();

    $fresh = Workspace::query()->whereKey($workspace->id)->first();

    expect($fresh->invite_link_token)
        ->not->toBe($original)
        ->toHaveLength(40);
});
