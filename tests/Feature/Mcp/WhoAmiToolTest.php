<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\WhoAmiTool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

it('returns current user info', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertSee($this->user->name)
        ->assertSee($this->user->email);
});

it('returns current workspace info', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertSee($this->workspace->name);
});

it('returns workspace members', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member);

    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertSee($this->user->name)
        ->assertSee($member->name)
        ->assertSee($member->email);
});

it('returns wildcard abilities when no token', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertSee('"*"');
});

describe('token abilities', function (): void {
    it('requires read ability', function (): void {
        $token = $this->user->createToken('test', ['create']);
        $this->user->withAccessToken($token->accessToken);

        RelaticleServer::actingAs($this->user)
            ->tool(WhoAmiTool::class)
            ->assertHasErrors(['Invalid ability provided.']);
    });

    it('allows read-only token', function (): void {
        $token = $this->user->createToken('test', ['read']);
        $this->user->withAccessToken($token->accessToken);

        RelaticleServer::actingAs($this->user)
            ->tool(WhoAmiTool::class)
            ->assertOk();
    });
});
