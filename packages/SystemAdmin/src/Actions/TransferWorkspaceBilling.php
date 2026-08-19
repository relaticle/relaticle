<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use App\Enums\Plan;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;
use Relaticle\SystemAdmin\Exceptions\TransferRefused;

final readonly class TransferWorkspaceBilling
{
    public function __construct(private CreditService $credits) {}

    /**
     * Move a workspace's Stripe customer and every subscription it owns to
     * another workspace of the same owner.
     *
     * Nothing is sent to Stripe. A subscription cannot change customer there,
     * and it does not need to: the customer itself changes hands, so the same
     * card keeps being charged on the same date. `subscription_items` has no
     * team column, so items follow their parent row.
     *
     * @throws TransferRefused when the pair fails a transfer precondition
     */
    public function execute(Team $source, Team $target, string $sysadminId): void
    {
        $logContext = DB::transaction(function () use ($source, $target, $sysadminId): array {
            /** @var Team $lockedSource */
            $lockedSource = Team::query()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();

            /** @var Team $lockedTarget */
            $lockedTarget = Team::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $plan = $this->assertTransferable($lockedSource, $lockedTarget);

            $sourceStripeId = $lockedSource->stripe_id;
            $sourcePmType = $lockedSource->pm_type;
            $sourcePmLastFour = $lockedSource->pm_last_four;

            $lockedSource->forceFill([
                'stripe_id' => null,
                'pm_type' => null,
                'pm_last_four' => null,
                'plan' => Plan::default(),
                'trial_ends_at' => null,
            ])->save();

            $lockedTarget->forceFill([
                'stripe_id' => $sourceStripeId,
                'pm_type' => $sourcePmType,
                'pm_last_four' => $sourcePmLastFour,
                'plan' => $plan,
                'trial_ends_at' => null,
            ])->save();

            Subscription::query()
                ->where('team_id', $lockedSource->getKey())
                ->update(['team_id' => $lockedTarget->getKey()]);

            // CreditPeriodResolver reads the subscriptions relation to pick the
            // billing anchor, and loadMissing() would keep the pre-move copy.
            $lockedSource->unsetRelation('subscriptions');
            $lockedTarget->unsetRelation('subscriptions');

            $this->credits->resetPeriod($lockedTarget, $sysadminId);
            $this->credits->resetPeriod($lockedSource, $sysadminId);

            return [
                'source_team_id' => $lockedSource->getKey(),
                'target_team_id' => $lockedTarget->getKey(),
                'stripe_customer' => $lockedTarget->stripe_id,
                'plan' => $plan->value,
                'sysadmin_id' => $sysadminId,
            ];
        });

        Log::info('Workspace billing transferred', $logContext);

        $source->refresh();
        $target->refresh();
    }

    /** @throws TransferRefused */
    private function assertTransferable(Team $source, Team $target): Plan
    {
        throw_if($source->is($target), TransferRefused::class, 'Source and target are the same workspace.');
        throw_if($source->stripe_id === null, TransferRefused::class, 'The source workspace has no Stripe customer.');
        throw_if($target->stripe_id !== null, TransferRefused::class, 'The target workspace already has its own Stripe customer. Cancel and re-subscribe manually instead.');
        throw_if($source->user_id !== $target->user_id, TransferRefused::class, 'Both workspaces must have the same owner.');

        $source->loadMissing('subscriptions');
        $subscription = $source->subscription();

        throw_if(
            ! $subscription instanceof Subscription || ! $subscription->valid(),
            TransferRefused::class,
            'The source workspace has no valid subscription to transfer.',
        );

        $plan = Plan::fromStripePrice($subscription->stripe_price);

        throw_if(! $plan instanceof Plan, TransferRefused::class, 'The subscription price is not mapped to a plan.');

        return $plan;
    }
}
