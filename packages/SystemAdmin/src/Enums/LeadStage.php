<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Enums;

use App\Enums\BillingStatus;

enum LeadStage: string
{
    case Trialing = 'trialing';
    case TrialEnded = 'trial_ended';
    case Free = 'free';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::TrialEnded => 'Trial ended',
            self::Free => 'Free',
        };
    }

    public function getHelp(): string
    {
        return match ($this) {
            self::Trialing => 'On the Pro trial now. The trial that ends soonest comes first.',
            self::TrialEnded => 'The trial ended without a subscription, so the workspace is paused on the plan choice. The most recent comes first.',
            self::Free => 'Never trialed: free, grandfathered, granted or a cancelled subscription. The most active in the last 30 days comes first.',
        };
    }

    /**
     * @return list<BillingStatus>
     */
    public function billingStatuses(): array
    {
        return match ($this) {
            self::Trialing => [BillingStatus::Trialing],
            self::TrialEnded => [BillingStatus::TrialEnded],
            self::Free => [BillingStatus::Free, BillingStatus::Grandfathered, BillingStatus::Granted, BillingStatus::SubscriptionEnded],
        };
    }
}
