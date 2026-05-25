<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

final readonly class RequestEmailAccessAction
{
    public function execute(Email $email, User $requester, EmailPrivacyTier $tierRequested): ?EmailAccessRequest
    {
        $existing = EmailAccessRequest::query()
            ->where('email_id', $email->getKey())
            ->where('requester_id', $requester->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->exists();

        if ($existing) {
            return null;
        }

        /** @var EmailAccessRequest $request */
        $request = EmailAccessRequest::query()->create([
            'email_id' => $email->getKey(),
            'requester_id' => $requester->getKey(),
            'owner_id' => $email->user_id,
            'tier_requested' => $tierRequested->value,
            'status' => EmailAccessRequestStatus::PENDING,
        ]);

        $email->user?->notify(new EmailAccessRequestedNotification($request));

        return $request;
    }
}
