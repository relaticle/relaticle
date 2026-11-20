<?php

declare(strict_types=1);

use App\Actions\Chat\SeedWorkspaceCreditBalance;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Laravel\Ai\Responses\Data\TextUsage;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Enums\RefundCause;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Queries\CreditUsageQuery;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Cloud\Billing\Livewire\CreditUsage;

mutates(CreditUsage::class, CreditUsageQuery::class, AiCreditType::class);

beforeEach(function (): void {
    withHostedBilling();
    $this->travelTo('2026-11-13 10:00:00');

    $this->owner = User::factory()->withPersonalWorkspace()->create(['name' => 'Ada Owner', 'timezone' => 'UTC']);
    $this->workspace = $this->owner->currentWorkspace;
    $this->workspace->forceFill(['plan' => Plan::Pro])->save();
    $this->teammate = User::factory()->create(['name' => 'Grace Teammate']);
    $this->workspace->users()->attach($this->teammate, ['role' => 'member']);

    $this->credits = resolve(CreditService::class);
    $this->models = collect(resolve(ModelRegistry::class)->all())->unique(fn ($model): string => $model->displayLabel())->values();

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

function spendReply(User $user, string $model, string $key): void
{
    $credits = resolve(CreditService::class);
    $workspace = test()->workspace;

    $credits->reserveCredit($workspace, "reserve-{$key}", null, (string) $user->getKey());
    $credits->settleReservation($workspace, $user, AiCreditType::Chat, $model, new TextUsage(100, 50), toolCallsCount: 2, resolutionKey: "resolve-{$key}");
}

function usedCredits(Workspace $workspace): int
{
    return AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole()->credits_used;
}

it('counts exactly the credits the balance says were used since the period began', function (): void {
    [$first, $second] = [$this->models[0], $this->models[1]];

    spendReply($this->owner, $first->id, 'before-reset');
    $this->travelTo('2026-11-13 11:00:00');
    $this->credits->resetPeriod($this->workspace->refresh());

    $this->travelTo('2026-11-13 12:00:00');
    spendReply($this->owner, $first->id, 'a');
    spendReply($this->teammate, $first->id, 'b');
    spendReply($this->teammate, $first->id, 'c');
    $this->credits->reserveCredit($this->workspace, 'reserve-refunded', null, (string) $this->teammate->getKey());
    $this->credits->refundReservation($this->workspace, RefundCause::Failed, resolutionKey: 'resolve-refunded');
    $this->credits->reserveCredit($this->workspace, 'reserve-stopped', null, (string) $this->owner->getKey());
    $this->credits->settleReservedMinimum($this->workspace, $this->owner, null, 'resolve-stopped', 'cancelled');
    $this->credits->deduct($this->workspace, $this->teammate, AiCreditType::Summary, $second->id, 10, 10);
    $this->credits->addPurchasedCredits($this->workspace, 1000, 'pack-cs_usage', ['session_id' => 'cs_usage']);

    $other = User::factory()->withPersonalWorkspace()->create();
    resolve(CreditService::class)->deduct($other->currentWorkspace, $other, AiCreditType::Chat, $first->id, 1, 1);

    $used = usedCredits($this->workspace);

    livewire(CreditUsage::class)
        ->assertSeeInOrder([number_format($used), __('cloud::billing.usage_tab.used_since', ['date' => 'Nov 13, 2026'])])
        ->assertSeeInOrder(['Grace Teammate', 'Ada Owner'])
        ->assertSee($first->displayLabel())
        ->assertSee($second->displayLabel())
        ->assertSee(__('cloud::billing.usage_tab.stopped'));

    expect($used)->toBeGreaterThan(0);
});

it('buckets each day in the viewer\'s timezone', function (): void {
    $this->owner->forceFill(['timezone' => 'Asia/Yerevan'])->save();

    $this->travelTo('2026-11-13 21:30:00');
    spendReply($this->owner, $this->models[0]->id, 'late');

    $used = usedCredits($this->workspace);

    livewire(CreditUsage::class)
        ->assertSee(__('cloud::billing.usage_tab.bar', ['date' => 'Nov 14, 2026', 'credits' => $used]))
        ->assertDontSee(__('cloud::billing.usage_tab.bar', ['date' => 'Nov 13, 2026', 'credits' => $used]));
});

it('leaves out the member and model breakdowns when one member used one model', function (): void {
    spendReply($this->owner, $this->models[0]->id, 'solo');

    livewire(CreditUsage::class)
        ->assertDontSee(__('cloud::billing.usage_tab.by_member'))
        ->assertDontSee(__('cloud::billing.usage_tab.by_model'));
});

it('says so when nothing was used yet', function (): void {
    resolve(SeedWorkspaceCreditBalance::class)->execute($this->workspace);

    livewire(CreditUsage::class)
        ->assertSee(__('cloud::billing.usage_tab.empty', ['date' => 'Nov 13, 2026']));
});

it('shows usage only to someone who manages billing', function (): void {
    spendReply($this->owner, $this->models[0]->id, 'private');

    $this->actingAs($this->teammate);

    livewire(CreditUsage::class)->assertForbidden();
});

it('names a member who left the workspace without exposing who they were', function (): void {
    spendReply($this->teammate, $this->models[0]->id, 'leaver');
    spendReply($this->owner, $this->models[1]->id, 'stayer');
    $this->workspace->users()->detach($this->teammate);

    livewire(CreditUsage::class)
        ->assertSee(__('cloud::billing.usage_tab.former_member'))
        ->assertDontSee('Grace Teammate');
});
