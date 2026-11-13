<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\WhoAmiTool;
use App\Models\User;
use App\Models\Workspace;
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

/**
 * @param  list<string>  $abilities
 * @return list<string>
 */
function abilitiesReportedTo(User $user, array $abilities, Workspace $workspace): array
{
    auth()->forgetGuards();

    return test()
        ->withToken($user->createToken('test', $abilities)->plainTextToken)
        ->withHeader('X-Workspace-Id', $workspace->id)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'who-ami-tool', 'arguments' => (object) []],
        ])
        ->assertOk()
        ->json('result.structuredContent.token_abilities');
}

it('reports a viewer\'s access token only the abilities the role grants', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    expect(abilitiesReportedTo($viewer, ['read', 'create', 'delete', 'email:read', 'email:send'], $this->workspace))
        ->toBe(['read', 'email:read']);
});

it('reports a member\'s access token every ability it holds', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);

    expect(abilitiesReportedTo($member, ['read', 'create', 'delete', 'email:read', 'email:send'], $this->workspace))
        ->toBe(['read', 'create', 'delete', 'email:read', 'email:send']);
});

it('reports a viewer\'s wildcard access token as the wildcard', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    expect(abilitiesReportedTo($viewer, ['*'], $this->workspace))->toBe(['*']);
});
