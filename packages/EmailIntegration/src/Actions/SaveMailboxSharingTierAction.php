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

        DB::transaction(function () use ($mailbox, $tier): void {
            $mailbox->forceFill(['sharing_tier' => $tier])->save();

            $this->applyRetroactive->executeForMailbox($mailbox, $this->privacy->tierForMailbox($mailbox));
        });
    }
}
