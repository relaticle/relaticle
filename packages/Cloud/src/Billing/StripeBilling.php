<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing;

use App\Actions\Billing\CancelWorkspaceSubscription;
use App\Actions\Billing\StartProTrial;
use App\Contracts\Billing;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing as BillingPage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use Laravel\Pennant\Feature;

final readonly class StripeBilling implements Billing
{
    public function __construct(
        private StartProTrial $startProTrial,
        private CancelWorkspaceSubscription $cancelSubscription,
        private CreditPackCatalog $creditPacks,
    ) {}

    public function url(Workspace $workspace): ?string
    {
        if (! Feature::active(BillingFeature::class)) {
            return null;
        }

        return BillingPage::getUrl(panel: 'app', tenant: $workspace);
    }

    public function sellsCredits(Workspace $workspace): bool
    {
        return $this->creditPacks->hasPurchasable();
    }

    public function startTrial(User $owner, Workspace $workspace): void
    {
        $this->startProTrial->execute($owner, $workspace);
    }

    public function cancelForDeletion(Workspace $workspace, bool $immediately): void
    {
        $this->cancelSubscription->execute($workspace, $immediately);
    }

    public function blocksDeletion(Workspace $workspace): bool
    {
        return $workspace->hasOpenManagedSubscription();
    }

    public function deletionNotice(Workspace $workspace): ?string
    {
        if (! $workspace->subscribed()) {
            return null;
        }

        return __('billing.deletion_notice');
    }
}
