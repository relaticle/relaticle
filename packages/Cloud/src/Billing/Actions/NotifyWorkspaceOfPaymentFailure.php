<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\Cloud\Billing\Notifications\PaymentFailedNotification;

/**
 * Tell the workspace owner that a renewal charge failed.
 *
 * The owner alone, because the billing page's portal button is owner-only:
 * anyone else would get an alarm about something they cannot act on.
 */
final readonly class NotifyWorkspaceOfPaymentFailure
{
    public function execute(Workspace $workspace): void
    {
        $owner = $workspace->owner()->first();

        if (! $owner instanceof User) {
            return;
        }

        $owner->notify(new PaymentFailedNotification($workspace));
    }
}
