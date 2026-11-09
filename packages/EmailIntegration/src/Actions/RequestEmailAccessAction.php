<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Notifications\RefreshNotificationBell;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

final readonly class RequestEmailAccessAction
{
    public function __construct(
        private DecideEmailAccessRequest $decide,
        private RefreshNotificationBell $refreshBell,
    ) {}

    public function execute(Email $email, User $requester, EmailPrivacyTier $tierRequested): ?EmailAccessRequest
    {
        // Access requests are only valid within the email's own workspace. Never
        // let a user from another workspace open a cross-workspace request (mirrors the workspace
        // check already enforced on the approval path).
        abort_unless($requester->current_workspace_id === $email->workspace_id, 403);

        $this->expireOverdueRequests($email, $requester);

        if ($email->hasPendingAccessRequestFrom($requester)) {
            return null;
        }

        $request = $this->openRequest($email, $requester, $tierRequested);

        if (! $request instanceof EmailAccessRequest) {
            return null;
        }

        $email->user?->notify(new EmailAccessRequestedNotification($request));

        if ($email->user instanceof User) {
            $this->refreshBell->execute($email->user);
        }

        return $request;
    }

    private function openRequest(Email $email, User $requester, EmailPrivacyTier $tierRequested): ?EmailAccessRequest
    {
        try {
            return DB::transaction(fn (): EmailAccessRequest => EmailAccessRequest::query()->create([
                'email_id' => $email->getKey(),
                'requester_id' => $requester->getKey(),
                'owner_id' => $email->user_id,
                'tier_requested' => $tierRequested->value,
                'status' => EmailAccessRequestStatus::PENDING,
                'workspace_id' => $email->workspace_id,
                'expires_at' => now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function expireOverdueRequests(Email $email, User $requester): void
    {
        $email->accessRequests()
            ->with('owner')
            ->where('requester_id', $requester->getKey())
            ->overdue()
            ->each(fn (EmailAccessRequest $overdue): bool => $this->decide->execute($overdue, EmailAccessRequestStatus::EXPIRED, null));
    }
}
