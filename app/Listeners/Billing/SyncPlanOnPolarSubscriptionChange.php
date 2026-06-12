<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Actions\Billing\SyncTeamPlanFromSubscription;
use App\Models\Team;
use Danestves\LaravelPolar\Events\SubscriptionActive;
use Danestves\LaravelPolar\Events\SubscriptionCanceled;
use Danestves\LaravelPolar\Events\SubscriptionCreated;
use Danestves\LaravelPolar\Events\SubscriptionRevoked;
use Danestves\LaravelPolar\Events\SubscriptionUpdated;

final readonly class SyncPlanOnPolarSubscriptionChange
{
    public function __construct(private SyncTeamPlanFromSubscription $syncTeamPlan) {}

    public function handle(SubscriptionCreated|SubscriptionActive|SubscriptionUpdated|SubscriptionCanceled|SubscriptionRevoked $event): void
    {
        $team = $event->billable;

        if (! $team instanceof Team) {
            return;
        }

        $this->syncTeamPlan->handle($team, $event->subscription);
    }
}
