<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class SaveMailboxSharingTierAction
{
    public function __construct(
        private ApplyDefaultSharingTierToExistingEmailsAction $applyRetroactive,
        private PrivacyService $privacy,
    ) {}

    public function execute(User $actor, ConnectedAccount $mailbox, ?EmailPrivacyTier $tier): void
    {
        abort_unless($mailbox->user_id === $actor->getKey(), 403);

        $previous = $this->privacy->tierForMailbox($mailbox);

        DB::transaction(function () use ($mailbox, $tier, $previous): void {
            $mailbox->forceFill(['sharing_tier' => $tier])->save();

            $effective = $this->privacy->tierForMailbox($mailbox);

            if ($effective !== $previous) {
                $this->applyRetroactive->executeForMailbox($mailbox, $effective);
            }
        });
    }
}
