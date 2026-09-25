<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

mutates(Workspace::class);

it('has a plan column on workspaces', function (): void {
    expect(Schema::hasColumn('workspaces', 'plan'))->toBeTrue();
});

it('has an explicit hosted Free grandfathering column', function (): void {
    expect(Schema::hasColumn('workspaces', 'hosted_free_grandfathered_at'))->toBeTrue();
});

it('defaults new workspaces to the Free plan value', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    // Cast wired in Task 3; for now assert the raw default.
    expect($user->currentWorkspace->getRawOriginal('plan'))->toBe('free');
});

it('does not have a plan column on ai_credit_balances after migration', function (): void {
    expect(Schema::hasColumn('ai_credit_balances', 'plan'))->toBeFalse();
});

it('casts plan to the Plan enum on the Workspace model', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    expect($user->currentWorkspace->plan)->toBeInstanceOf(Plan::class);
    expect($user->currentWorkspace->plan)->toBe(Plan::Free);
});

it('does not list plan in the fillable attribute', function (): void {
    expect((new Workspace)->getFillable())->not->toContain('plan');
});
