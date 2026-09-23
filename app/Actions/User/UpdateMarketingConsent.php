<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;

final readonly class UpdateMarketingConsent
{
    public function execute(User $user, bool $consented): void
    {
        if ($consented === ($user->marketing_consent_at !== null)) {
            return;
        }

        $user->update(['marketing_consent_at' => $consented ? now() : null]);

        SyncSubscriberJob::dispatchFor((string) $user->id);
    }
}
