<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(SubscriptionResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
});

it('lists subscriptions with team and status for sysadmins', function (): void {
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;
    $subscription = $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_list',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    livewire(ListSubscriptions::class)
        ->assertCanSeeTableRecords([$subscription])
        ->assertCanRenderTableColumn('owner.name')
        ->assertCanRenderTableColumn('stripe_status')
        ->assertSee($team->name);
});

it('filters subscriptions by status', function (): void {
    /** @var Team $teamA */
    $teamA = User::factory()->withPersonalTeam()->create()->currentTeam;
    /** @var Team $teamB */
    $teamB = User::factory()->withPersonalTeam()->create()->currentTeam;

    $active = $teamA->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_a', 'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1,
    ]);
    $canceled = $teamB->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_b', 'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1, 'ends_at' => now()->subDay(),
    ]);

    livewire(ListSubscriptions::class)
        ->filterTable('stripe_status', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$canceled]);
});
