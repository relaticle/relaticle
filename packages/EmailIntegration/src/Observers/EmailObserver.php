<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use App\Jobs\ClassifyEmailJob;
use App\Models\User;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
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
        $owner = User::query()->find($email->user_id);

        if ($owner && ! $email->isDirty('privacy_tier')) {
            $email->privacy_tier = $this->privacyService->defaultTierForUser($owner);
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

        dispatch(new ClassifyEmailJob($email->getKey()))->delay(now()->addSeconds(5));
    }
}
