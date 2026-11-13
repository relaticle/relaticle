<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Listeners\ForgetConversationCountsListener;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class SaveMailboxSharingTier
{
    public function __construct(
        private ApplyDefaultSharingTierToExistingEmailsAction $applyRetroactive,
        private PrivacyService $privacy,
        private ForgetConversationCountsListener $forgetCounts,
    ) {}

    public function execute(User $actor, ConnectedAccount $mailbox, EmailPrivacyTier $tier): void
    {
        abort_unless($mailbox->user_id === $actor->getKey(), 403);

        DB::transaction(function () use ($mailbox, $tier): void {
            $mailbox->forceFill(['sharing_tier' => $tier])->save();

            $this->applyRetroactive->execute($mailbox, $this->privacy->tierForMailbox($mailbox));
        });

        // A mailbox-wide change sends no per-conversation signal, so the counts it moves are dropped here.
        DB::afterCommit(fn () => $this->forgetCounts->forWorkspace($mailbox->workspace_id));
    }
}
