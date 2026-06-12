<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

mutates(Team::class);

it('has a plan column on teams', function (): void {
    expect(Schema::hasColumn('teams', 'plan'))->toBeTrue();
});

it('defaults new teams to the Free plan value', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    // Cast wired in Task 3 — for now assert the raw default.
    expect($user->currentTeam->getRawOriginal('plan'))->toBe('free');
});

it('does not have a plan column on ai_credit_balances after migration', function (): void {
    expect(Schema::hasColumn('ai_credit_balances', 'plan'))->toBeFalse();
});

it('casts plan to the Plan enum on the Team model', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    expect($user->currentTeam->plan)->toBeInstanceOf(Plan::class);
    expect($user->currentTeam->plan)->toBe(Plan::Free);
});

it('does not list plan in the fillable attribute', function (): void {
    expect((new Team)->getFillable())->not->toContain('plan');
});
