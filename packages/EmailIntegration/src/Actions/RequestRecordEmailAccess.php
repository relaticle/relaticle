<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Notifications\RefreshNotificationBell;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

final readonly class RequestRecordEmailAccess
{
    public function __construct(
        private DecideEmailAccessRequest $decide,
        private RefreshNotificationBell $refreshBell,
    ) {}

    public function execute(User $requester, Company|People|Opportunity $record, User $owner, EmailPrivacyTier $tier, ?string $note): ?EmailAccessRequest
    {
        $note = filled($note) ? trim($note) : null;

        abort_if($note !== null && mb_strlen($note) > EmailAccessRequest::NOTE_MAX_LENGTH, 422);
        abort_unless($tier === EmailPrivacyTier::SUBJECT || $tier === EmailPrivacyTier::FULL, 422);
        abort_unless($requester->current_workspace_id === $record->workspace_id, 403);
        abort_unless($owner->belongsToWorkspaceId($record->workspace_id), 403);
        abort_unless($requester->can('view', $record), 403);
        abort_if($requester->is($owner), 422);

        $this->expireOverdueRequests($requester, $record, $owner);

        $request = $this->openRequest($requester, $record, $owner, $tier, $note);

        if (! $request instanceof EmailAccessRequest) {
            return null;
        }

        activity(Activity::EMAIL_ACCESS_LOG)
            ->performedOn($request)
            ->causedBy($requester)
            ->event('requested')
            ->withProperties([
                'tier' => $tier->value,
                'has_note' => $note !== null,
                'record_type' => $record->getMorphClass(),
                'record_id' => (string) $record->getKey(),
            ])
            ->log('requested');

        $owner->notify(new EmailAccessRequestedNotification($request));
        $this->refreshBell->execute($owner);

        return $request;
    }

    private function openRequest(User $requester, Company|People|Opportunity $record, User $owner, EmailPrivacyTier $tier, ?string $note): ?EmailAccessRequest
    {
        try {
            return DB::transaction(fn (): EmailAccessRequest => EmailAccessRequest::query()->create([
                'email_id' => null,
                'emailable_type' => $record->getMorphClass(),
                'emailable_id' => $record->getKey(),
                'requester_id' => $requester->getKey(),
                'owner_id' => $owner->getKey(),
                'tier_requested' => $tier->value,
                'note' => $note,
                'status' => EmailAccessRequestStatus::PENDING,
                'workspace_id' => $record->workspace_id,
                'expires_at' => now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function expireOverdueRequests(User $requester, Company|People|Opportunity $record, User $owner): void
    {
        EmailAccessRequest::query()
            ->with('owner')
            ->whereNull('email_id')
            ->where('emailable_type', $record->getMorphClass())
            ->where('emailable_id', $record->getKey())
            ->where('requester_id', $requester->getKey())
            ->where('owner_id', $owner->getKey())
            ->overdue()
            ->each(fn (EmailAccessRequest $overdue): bool => $this->decide->execute($overdue, EmailAccessRequestStatus::EXPIRED, null));
    }
}
