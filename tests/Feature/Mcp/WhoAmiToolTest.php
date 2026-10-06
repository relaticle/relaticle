<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\WhoAmiTool;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Pennant\Feature;

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

it('returns the caller\'s role and what it allows in the workspace', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspace);

    RelaticleServer::actingAs($member->fresh())
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('workspace.role', 'Member')
            ->where('workspace.capabilities', fn (mixed $capabilities): bool => collect($capabilities)->contains('records.create')
                && ! collect($capabilities)->contains('members.manage'))
            ->etc());
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

it('reports no email ability for a token that holds the wildcard', function (): void {
    $this->user->withAccessToken($this->user->createToken('test', ['*'])->accessToken);

    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('token_abilities', ['*'])
            ->etc());
});

it('reports the email abilities a personal access token holds beside its record abilities', function (): void {
    $this->user->withAccessToken($this->user->createToken('test', ['read', 'email:send', 'update', 'email:read'])->accessToken);

    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('token_abilities', ['read', 'update', 'email:read', 'email:send'])
            ->etc());
});

it('reports no email ability while the email feature is off', function (): void {
    Feature::define(EmailIntegration::class, false);

    $this->user->withAccessToken($this->user->createToken('test', ['read', 'email:read'])->accessToken);

    RelaticleServer::actingAs($this->user)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('token_abilities', ['read'])
            ->etc());
});
