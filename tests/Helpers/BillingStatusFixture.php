<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Cashier\Subscription;

final class BillingStatusFixture
{
    public static function workspace(): Workspace
    {
        return User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();
    }

    /**
     * @return array<string, array{0: callable(Workspace): void, 1: BillingStatus}>
     */
    public static function arrangements(): array
    {
        return [
            'a trial reads Trial, never Pro' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save(),
                BillingStatus::Trialing,
            ],
            'a paid subscription reads Pro' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['plan' => Plan::Pro])->save();
                    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::Subscribed,
            ],
            'a lapsed subscription reads Past due, not Pro' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['plan' => Plan::Pro])->save();
                    Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::PastDue,
            ],
            'a past-due subscription past its end date reads Subscription ended' => [
                function (Workspace $workspace): void {
                    Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey(), 'ends_at' => now()->subDay()]);
                },
                BillingStatus::SubscriptionEnded,
            ],
            'a managed plan whose own invoice is overdue reads Past due' => [
                function (Workspace $workspace): void {
                    config()->set('services.stripe.products.concierge', 'prod_concierge_fixture');
                    $workspace->forceFill(['plan' => Plan::Concierge])->save();
                    Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey(), 'type' => SubscriptionType::Managed->value])
                        ->items()->create(['stripe_id' => 'si_fixture_overdue', 'stripe_product' => 'prod_concierge_fixture', 'stripe_price' => 'price_fixture_overdue', 'quantity' => 1]);
                },
                BillingStatus::PastDue,
            ],
            'a managed plan with an overdue invoice for another managed plan still reads its plan' => [
                function (Workspace $workspace): void {
                    config()->set('services.stripe.products.concierge', 'prod_concierge_fixture');
                    config()->set('services.stripe.products.enterprise', 'prod_enterprise_fixture');
                    $workspace->forceFill(['plan' => Plan::Concierge])->save();
                    Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey(), 'type' => SubscriptionType::Managed->value])
                        ->items()->create(['stripe_id' => 'si_fixture_other', 'stripe_product' => 'prod_enterprise_fixture', 'stripe_price' => 'price_fixture_other', 'quantity' => 1]);
                },
                BillingStatus::Concierge,
            ],
            'an expired trial awaiting the nightly downgrade reads Trial ended, not Granted' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save(),
                BillingStatus::TrialEnded,
            ],
            'a trial paused by the nightly downgrade still reads Trial ended' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Free, 'pro_trial_used_at' => now()->subDays(15)])->save(),
                BillingStatus::TrialEnded,
            ],
            'a trial that ended beside an abandoned checkout reads Trial ended' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['pro_trial_used_at' => now()->subDays(15)])->save();
                    Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::TrialEnded,
            ],
            'a cancelled subscription reads Subscription ended' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['pro_trial_used_at' => now()->subMonths(3)])->save();
                    Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::SubscriptionEnded,
            ],
            'a cancelled subscription followed by an abandoned checkout still reads Subscription ended' => [
                function (Workspace $workspace): void {
                    Subscription::factory()->canceled()->create([
                        'workspace_id' => $workspace->getKey(),
                        'created_at' => now()->subMonths(2),
                        'updated_at' => now()->subMonths(2),
                    ]);
                    Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::SubscriptionEnded,
            ],
            'a cancelled subscriber granted Pro by hand reads Granted' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['plan' => Plan::Pro])->save();
                    Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::Granted,
            ],
            'a grandfathered workspace whose subscription ended reads Free (legacy)' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['hosted_free_grandfathered_at' => now()->subYear()])->save();
                    Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::Grandfathered,
            ],
            'a trial-ended workspace with an unpaid managed invoice still reads Trial ended' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['pro_trial_used_at' => now()->subDays(15)])->save();
                    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey(), 'type' => SubscriptionType::ManagedPending->value]);
                },
                BillingStatus::TrialEnded,
            ],
            'a trial-ended workspace whose managed invoice was withdrawn still reads Trial ended' => [
                function (Workspace $workspace): void {
                    $workspace->forceFill(['pro_trial_used_at' => now()->subDays(15)])->save();
                    Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey(), 'type' => SubscriptionType::ManagedPending->value]);
                },
                BillingStatus::TrialEnded,
            ],
            'a checkout abandoned without a trial reads Free' => [
                function (Workspace $workspace): void {
                    Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
                },
                BillingStatus::Free,
            ],
            'a hand-assigned plan reads Granted, not Pro' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro])->save(),
                BillingStatus::Granted,
            ],
            'an enterprise workspace reads Enterprise' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Enterprise])->save(),
                BillingStatus::Enterprise,
            ],
            'a concierge workspace reads Concierge' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Concierge])->save(),
                BillingStatus::Concierge,
            ],
            'a concierge grant past an old trial date reads Concierge, not Trial ended' => [
                fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Concierge, 'trial_ends_at' => now()->subHour()])->save(),
                BillingStatus::Concierge,
            ],
            'a pre-billing workspace reads Free (legacy)' => [
                fn (Workspace $workspace) => $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save(),
                BillingStatus::Grandfathered,
            ],
            'a workspace with nothing bought reads Free' => [
                fn (Workspace $workspace): null => null,
                BillingStatus::Free,
            ],
        ];
    }
}
