<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

describe('read-only token', function (): void {
    beforeEach(function (): void {
        $this->token = $this->user->createToken('test', ['read'])->plainTextToken;
    });

    it('can list companies', function (): void {
        $this->withToken($this->token)
            ->getJson('/api/v1/companies')
            ->assertOk();
    });

    it('can show a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk();
    });

    it('cannot create a company', function (): void {
        $this->withToken($this->token)
            ->postJson('/api/v1/companies', ['name' => 'Blocked'])
            ->assertForbidden();
    });

    it('cannot update a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->putJson("/api/v1/companies/{$company->id}", ['name' => 'Blocked'])
            ->assertForbidden();
    });

    it('cannot delete a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertForbidden();
    });
});

describe('create-only token', function (): void {
    beforeEach(function (): void {
        $this->token = $this->user->createToken('test', ['create'])->plainTextToken;
    });

    it('cannot list companies', function (): void {
        $this->withToken($this->token)
            ->getJson('/api/v1/companies')
            ->assertForbidden();
    });

    it('can create a company', function (): void {
        $this->withToken($this->token)
            ->postJson('/api/v1/companies', ['name' => 'Allowed Corp'])
            ->assertCreated();
    });

    it('cannot update a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->putJson("/api/v1/companies/{$company->id}", ['name' => 'Blocked'])
            ->assertForbidden();
    });

    it('cannot delete a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertForbidden();
    });
});

describe('update-only token', function (): void {
    beforeEach(function (): void {
        $this->token = $this->user->createToken('test', ['update'])->plainTextToken;
    });

    it('cannot list companies', function (): void {
        $this->withToken($this->token)
            ->getJson('/api/v1/companies')
            ->assertForbidden();
    });

    it('cannot create a company', function (): void {
        $this->withToken($this->token)
            ->postJson('/api/v1/companies', ['name' => 'Blocked'])
            ->assertForbidden();
    });

    it('can update a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->putJson("/api/v1/companies/{$company->id}", ['name' => 'Updated'])
            ->assertOk();
    });

    it('cannot delete a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertForbidden();
    });
});

describe('delete-only token', function (): void {
    beforeEach(function (): void {
        $this->token = $this->user->createToken('test', ['delete'])->plainTextToken;
    });

    it('cannot list companies', function (): void {
        $this->withToken($this->token)
            ->getJson('/api/v1/companies')
            ->assertForbidden();
    });

    it('cannot create a company', function (): void {
        $this->withToken($this->token)
            ->postJson('/api/v1/companies', ['name' => 'Blocked'])
            ->assertForbidden();
    });

    it('can delete a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertNoContent();
    });
});

describe('wildcard token', function (): void {
    beforeEach(function (): void {
        $this->token = $this->user->createToken('test', ['*'])->plainTextToken;
    });

    it('can list companies', function (): void {
        $this->withToken($this->token)
            ->getJson('/api/v1/companies')
            ->assertOk();
    });

    it('can create a company', function (): void {
        $this->withToken($this->token)
            ->postJson('/api/v1/companies', ['name' => 'Wildcard Corp'])
            ->assertCreated();
    });

    it('can update a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->putJson("/api/v1/companies/{$company->id}", ['name' => 'Updated'])
            ->assertOk();
    });

    it('can delete a company', function (): void {
        $company = Company::factory()->for($this->team)->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertNoContent();
    });
});

describe('multi-ability token', function (): void {
    it('read+create token can list and create but not update or delete', function (): void {
        $token = $this->user->createToken('test', ['read', 'create'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/companies')
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/companies', ['name' => 'Multi Corp'])
            ->assertCreated();

        $company = Company::factory()->for($this->team)->create();

        $this->withToken($token)
            ->putJson("/api/v1/companies/{$company->id}", ['name' => 'Blocked'])
            ->assertForbidden();

        $this->withToken($token)
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertForbidden();
    });
});
