<?php

declare(strict_types=1);

use App\Mcp\Prompts\CrmOverviewPrompt;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Models\Company;
use App\Models\User;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;

function listedResourceUris(User $user, array $abilities): array
{
    return test()
        ->withToken($user->createToken('test', $abilities)->plainTextToken)
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list'])
        ->assertOk()
        ->json('result.resources.*.uri');
}

beforeEach(function () {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

describe('read-only token', function (): void {
    beforeEach(function (): void {
        $token = $this->user->createToken('test', ['read']);
        $this->user->withAccessToken($token->accessToken);
    });

    it('lists the schema resources, the summary and the overview prompt', function (): void {
        expect(listedResourceUris($this->user, ['read']))->toEqualCanonicalizing([
            'relaticle://schema/company',
            'relaticle://schema/people',
            'relaticle://schema/opportunity',
            'relaticle://schema/task',
            'relaticle://schema/note',
            'relaticle://summary/crm',
        ]);

        RelaticleServer::actingAs($this->user)->prompts()->assertRegistered(CrmOverviewPrompt::class);
    });

    it('can list companies', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk();
    });

    it('cannot create a company', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, ['name' => 'Blocked'])
            ->assertHasErrors(['Invalid ability provided.']);
    });

    it('cannot update a company', function (): void {
        $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateCompanyTool::class, [
                'id' => $company->id,
                'name' => 'Blocked',
            ])
            ->assertHasErrors(['Invalid ability provided.']);
    });

    it('cannot delete a company', function (): void {
        $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteCompanyTool::class, [
                'id' => $company->id,
            ])
            ->assertHasErrors(['Invalid ability provided.']);
    });
});

describe('create-only token', function (): void {
    beforeEach(function (): void {
        $token = $this->user->createToken('test', ['create']);
        $this->user->withAccessToken($token->accessToken);
    });

    it('hides the schema resources, the summary and the overview prompt', function (): void {
        expect(listedResourceUris($this->user, ['create']))->toBe([]);

        RelaticleServer::actingAs($this->user)->prompts()->assertNotRegistered(CrmOverviewPrompt::class);
    });

    it('cannot list companies', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertHasErrors(['Invalid ability provided.']);
    });

    it('can create a company', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, ['name' => 'Allowed Corp'])
            ->assertOk();
    });

    it('cannot delete a company', function (): void {
        $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteCompanyTool::class, [
                'id' => $company->id,
            ])
            ->assertHasErrors(['Invalid ability provided.']);
    });
});

describe('wildcard token', function (): void {
    beforeEach(function (): void {
        $token = $this->user->createToken('test', ['*']);
        $this->user->withAccessToken($token->accessToken);
    });

    it('can list companies', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk();
    });

    it('can create a company', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, ['name' => 'Wildcard Corp'])
            ->assertOk();
    });

    it('can update a company', function (): void {
        $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateCompanyTool::class, [
                'id' => $company->id,
                'name' => 'Updated',
            ])
            ->assertOk();
    });

    it('can delete a company', function (): void {
        $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteCompanyTool::class, [
                'id' => $company->id,
            ])
            ->assertOk();
    });
});

describe('no token (session auth)', function (): void {
    it('allows all operations without a token', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk();

        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, ['name' => 'Session Corp'])
            ->assertOk();
    });
});

/**
 * `mcp:use` is the only scope Passport has registered and the only one the
 * authorization-server metadata advertises, so it is the only scope an OAuth
 * client can hold. Per-ability grants stay a personal-access-token feature.
 */
describe('passport oauth token', function (): void {
    it('allows the whole toolset when the token carries mcp:use', function (): void {
        Passport::actingAs($this->user, scopes: [Registrar::OAUTH_SCOPE]);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk();

        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, ['name' => 'Consented Corp'])
            ->assertOk();
    });

    it('refuses tool calls when the token is missing mcp:use', function (): void {
        Passport::actingAs($this->user, scopes: []);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertHasErrors(['Invalid ability provided.']);

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteCompanyTool::class, ['id' => Company::factory()->recycle([$this->user, $this->workspace])->create()->id])
            ->assertHasErrors(['Invalid ability provided.']);
    });
});
