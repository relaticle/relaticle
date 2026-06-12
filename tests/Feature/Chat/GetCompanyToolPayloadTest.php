<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Company\GetCompanyTool;

mutates(GetCompanyTool::class);

it('surfaces the account owner in the get-company payload', function (): void {
    $user = User::factory()->withPersonalTeam()->create(['name' => 'Olive Owner']);
    Auth::guard('web')->setUser($user);

    $company = Company::factory()->for($user->currentTeam)->create([
        'name' => 'Owned Co',
        'account_owner_id' => (string) $user->getKey(),
    ]);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->id])), true);

    expect($payload['account_owner'])->toBe([
        'id' => (string) $user->getKey(),
        'name' => 'Olive Owner',
    ]);
});

it('returns a null account owner when the company is unowned', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($user);

    $company = Company::factory()->for($user->currentTeam)->create([
        'name' => 'Unowned Co',
        'account_owner_id' => null,
    ]);

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->id])), true);

    expect($payload)->toHaveKey('account_owner')
        ->and($payload['account_owner'])->toBeNull();
});
