<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Notifications\RefreshNotificationBell;
use App\Models\ActivityLog\Activity;
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

    public function execute(Email $email, User $requester, EmailPrivacyTier $tierRequested, ?string $note = null): ?EmailAccessRequest
    {
        $note = filled($note) ? trim($note) : null;

        abort_if($note !== null && mb_strlen($note) > EmailAccessRequest::NOTE_MAX_LENGTH, 422);

        // Access requests are only valid within the email's own workspace. Never
        // let a user from another workspace open a cross-workspace request (mirrors the workspace
        // check already enforced on the approval path).
        abort_unless($requester->current_workspace_id === $email->workspace_id, 403);

        $this->expireOverdueRequests($email, $requester);

        if ($email->hasPendingAccessRequestFrom($requester)) {
            return null;
        }

        $request = $this->openRequest($email, $requester, $tierRequested, $note);

        if (! $request instanceof EmailAccessRequest) {
            return null;
        }

        $this->logRequested($request, $requester);

        $email->user?->notify(new EmailAccessRequestedNotification($request));

        if ($email->user instanceof User) {
            $this->refreshBell->execute($email->user);
        }

        return $request;
    }

    private function openRequest(Email $email, User $requester, EmailPrivacyTier $tierRequested, ?string $note): ?EmailAccessRequest
    {
        try {
            return DB::transaction(fn (): EmailAccessRequest => EmailAccessRequest::query()->create([
                'email_id' => $email->getKey(),
                'requester_id' => $requester->getKey(),
                'owner_id' => $email->user_id,
                'tier_requested' => $tierRequested->value,
                'note' => $note,
                'status' => EmailAccessRequestStatus::PENDING,
                'workspace_id' => $email->workspace_id,
                'expires_at' => now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function logRequested(EmailAccessRequest $request, User $requester): void
    {
        activity(Activity::EMAIL_ACCESS_LOG)
            ->performedOn($request)
            ->causedBy($requester)
            ->event('requested')
            ->withProperties(['tier' => $request->tier_requested, 'has_note' => filled($request->note)])
            ->log('requested');
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
