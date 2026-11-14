<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Queries\RecordEmailAccessQuery;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class ApproveEmailAccessRequestAction
{
    public function __construct(
        private DecideEmailAccessRequest $decide,
        private EmailSharingService $sharingService,
        private ConversationsQuery $conversations,
        private RecordEmailAccessQuery $recordMail,
    ) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor, Company|People|Opportunity|null $wholeRecord = null): bool
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);
        abort_unless($actor->belongsToWorkspaceId($accessRequest->workspace_id), 403);

        if (! $accessRequest->isOpen()) {
            return false;
        }

        $owner = $accessRequest->owner;
        $requester = $accessRequest->requester;

        if ($owner === null || $requester === null) {
            return false;
        }

        if ($wholeRecord !== null) {
            $this->widenToRecord($accessRequest, $wholeRecord);
        }

        $approved = $accessRequest->emailable_type !== null
            ? $this->approveRecord($accessRequest, $actor, $owner, $requester)
            : $this->approveEmail($accessRequest, $actor, $owner, $requester);

        if ($approved) {
            $requester->notify(new EmailAccessRespondedNotification($accessRequest));
        }

        return $approved;
    }

    private function approveEmail(EmailAccessRequest $accessRequest, User $actor, User $owner, User $requester): bool
    {
        $email = $accessRequest->email;

        if ($email === null) {
            return false;
        }

        abort_unless($requester->belongsToWorkspaceId($email->workspace_id), 403);

        $keys = filled($email->conversation_key) ? [(string) $email->conversation_key] : [];
        $before = $this->audiencesBefore($email->workspace_id, $keys);
        $tier = EmailPrivacyTier::from($accessRequest->tier_requested);

        $approved = DB::transaction(function () use ($accessRequest, $actor, $email, $owner, $requester, $tier): bool {
            if (! $this->decide->execute($accessRequest, EmailAccessRequestStatus::APPROVED, $actor)) {
                return false;
            }

            $this->sharingService->shareEmail($email, $owner, $requester, $tier);

            return true;
        });

        if ($approved) {
            $this->announce($email->workspace_id, $keys, $before);
        }

        return $approved;
    }

    private function approveRecord(EmailAccessRequest $accessRequest, User $actor, User $owner, User $requester): bool
    {
        $record = $accessRequest->record();

        if ($record === null) {
            if ($this->decide->execute($accessRequest, EmailAccessRequestStatus::DENIED, $actor)) {
                $requester->notify(new EmailAccessRespondedNotification($accessRequest));
            }

            return false;
        }

        abort_unless($requester->belongsToWorkspaceId($record->workspace_id), 403);

        $tier = EmailPrivacyTier::from($accessRequest->tier_requested);
        $keys = $this->recordMail->shareableConversationKeys($owner, $requester, $record, $tier);
        $before = $this->audiencesBefore($record->workspace_id, $keys);

        $approved = DB::transaction(function () use ($accessRequest, $actor, $owner, $requester, $record, $tier): bool {
            if (! $this->decide->execute($accessRequest, EmailAccessRequestStatus::APPROVED, $actor)) {
                return false;
            }

            EmailAccessRequest::query()->whereKey($accessRequest->getKey())->update([
                'emailable_type' => $record->getMorphClass(),
                'emailable_id' => $record->getKey(),
            ]);

            $count = 0;

            foreach ($this->recordMail->shareableCopies($owner, $requester, $record, $tier) as $email) {
                $this->sharingService->shareEmail($email, $owner, $requester, $tier);
                $count++;
            }

            $this->logRecordShare($accessRequest, $actor, $record, $count);

            return true;
        });

        if ($approved) {
            $this->announce($record->workspace_id, $keys, $before);
        }

        return $approved;
    }

    private function widenToRecord(EmailAccessRequest $accessRequest, Company|People|Opportunity $record): void
    {
        abort_unless($record->workspace_id === $accessRequest->workspace_id, 403);
        abort_unless(
            $accessRequest->email_id !== null
                && Email::query()->onRecord($record)->whereKey($accessRequest->email_id)->where('user_id', $accessRequest->owner_id)->exists(),
            422,
        );

        $accessRequest->forceFill([
            'emailable_type' => $record->getMorphClass(),
            'emailable_id' => $record->getKey(),
        ]);
    }

    private function logRecordShare(EmailAccessRequest $accessRequest, User $actor, Company|People|Opportunity $record, int $count): void
    {
        activity(Activity::EMAIL_ACCESS_LOG)
            ->performedOn($accessRequest)
            ->causedBy($actor)
            ->event('shared')
            ->withProperties([
                'record_type' => $record->getMorphClass(),
                'record_id' => (string) $record->getKey(),
                'count' => $count,
            ])
            ->log('shared');
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, list<string>>
     */
    private function audiencesBefore(string $workspaceId, array $keys): array
    {
        return ConversationChanged::broadcasts() ? $this->conversations->audiences($workspaceId, $keys) : [];
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, list<string>>  $before
     */
    private function announce(string $workspaceId, array $keys, array $before): void
    {
        foreach ($keys as $key) {
            event(new ConversationChanged($workspaceId, $key, $before[$key] ?? []));
        }
    }
}
