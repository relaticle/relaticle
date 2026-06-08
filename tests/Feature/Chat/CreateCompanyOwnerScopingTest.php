<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('rejects creating a company with account_owner_id from a foreign team', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $foreignUser = User::factory()->withPersonalTeam()->create();

    $this->actingAs($userA);

    expect(
        fn () => app(CreateCompany::class)->execute($userA, [
            'name' => 'Cross-Tenant Test Co',
            'account_owner_id' => (string) $foreignUser->getKey(),
        ])
    )->toThrow(ValidationException::class);
});

it('accepts a company whose account_owner_id is a member of the workspace', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $teammate = User::factory()->create();
    $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);

    $this->actingAs($owner);

    $company = app(CreateCompany::class)->execute($owner, [
        'name' => 'Friendly Co',
        'account_owner_id' => (string) $teammate->getKey(),
    ]);

    expect($company->account_owner_id)->toBe((string) $teammate->getKey());
});
