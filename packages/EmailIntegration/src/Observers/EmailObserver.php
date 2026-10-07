<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class EmailObserver
{
    public function __construct(
        private LinkEmailAction $linkEmail,
        private PrivacyService $privacyService,
    ) {}

    public function creating(Email $email): void
    {
        if ($email->isDirty('privacy_tier')) {
            if ($email->creation_source !== EmailCreationSource::SYNC) {
                $email->privacy_tier_customized = true;
            }

            return;
        }

        $mailbox = ConnectedAccount::withTrashed()->find($email->connected_account_id);

        if ($mailbox instanceof ConnectedAccount) {
            $email->privacy_tier = $this->privacyService->tierForMailbox($mailbox);
            // LinkEmailAction reads the email's workspace next.
            $email->setRelation('workspace', $mailbox->workspace);
        }
    }

    public function created(Email $email): void
    {
        // During sync jobs, participants are stored after Email::create().
        // StoreEmailAction calls linking manually once participants are ready.
        if ($email->participants()->doesntExist()) {
            return;
        }

        $this->linkEmail->execute($email);
    }
}
