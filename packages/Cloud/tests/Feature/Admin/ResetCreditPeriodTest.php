<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Cloud\Admin\Actions\ResetCreditPeriod;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(ResetCreditPeriod::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('resets the period of every given workspace on the chosen plan', function (): void {
    withHostedBilling();

    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();

    resolve(ResetCreditPeriod::class)->execute(SystemAdministrator::factory()->create(), Plan::Enterprise, [$first, $second]);

    expect($first->refresh()->plan)->toBe(Plan::Enterprise)
        ->and($second->refresh()->plan)->toBe(Plan::Enterprise)
        ->and(AiCreditBalance::query()->where('workspace_id', $first->getKey())->sole()->credits_remaining)->toBe(Plan::Enterprise->credits())
        ->and(AiCreditBalance::query()->where('workspace_id', $second->getKey())->sole()->credits_remaining)->toBe(Plan::Enterprise->credits());
});

it('refuses a reset the policy denies when the action is called directly', function (): void {
    $workspace = Workspace::factory()->create();
    $plan = $workspace->plan;
    Gate::before(static fn (): bool => false);

    expect(fn () => resolve(ResetCreditPeriod::class)->execute(SystemAdministrator::factory()->create(), Plan::Enterprise, [$workspace]))
        ->toThrow(HttpException::class);

    expect($workspace->refresh()->plan)->toBe($plan);
});
