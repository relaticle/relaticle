<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\Billing;
use App\Models\User;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Tools\GetCreditBalanceTool;

mutates(GetCreditBalanceTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create(['timezone' => 'Asia/Tokyo']);
    $this->workspace = $this->user->ownedWorkspaces()->first();
    $this->user->switchWorkspace($this->workspace);
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $this->workspace->getKey()], [
        'credits_remaining' => 137,
        'credits_used' => 63,
        'purchased_credits' => 50,
        'period_starts_at' => '2026-10-01 00:00:00',
        'period_ends_at' => '2026-10-31 20:00:00',
    ]);
});

it('reports the remaining credits, the allowance, purchased credits and usage', function (): void {
    withHostedBilling();

    $payload = json_decode(app(GetCreditBalanceTool::class)->handle(new Request([])), true);

    expect($payload['credits_remaining'])->toBe(137)
        ->and($payload['used_this_period'])->toBe(63)
        ->and($payload['purchased_credits_included'])->toBe(50)
        ->and($payload['period_allowance'])->toBe($this->workspace->plan->credits());
});

it('gives the reset time in the viewer timezone', function (): void {
    withHostedBilling();

    $payload = json_decode(app(GetCreditBalanceTool::class)->handle(new Request([])), true);

    expect($payload['period_resets_at'])->toBe('2026-11-01T05:00:00+09:00');
});

it('lets a member without billing access read the balance', function (): void {
    withHostedBilling();

    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->switchWorkspace($this->workspace);
    $this->actingAs($member);

    $payload = json_decode(app(GetCreditBalanceTool::class)->handle(new Request([])), true);

    expect($payload['credits_remaining'])->toBe(137);
});

it('tells the assistant credits are not metered on a self-hosted install', function (): void {
    Feature::define(Billing::class, false);
    AiCreditBalance::query()->where('workspace_id', $this->workspace->getKey())->update(['credits_remaining' => 0, 'purchased_credits' => 0]);

    $result = app(GetCreditBalanceTool::class)->handle(new Request([]));

    expect(json_decode($result, true))->toBe([
        'metered' => false,
        'note' => 'This installation does not meter AI credits. There is no credit limit.',
    ]);
});
