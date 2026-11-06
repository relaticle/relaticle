<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use App\Enums\CrmEntity;
use Illuminate\Support\Str;

enum ProposalEntity: string
{
    case Company = 'company';
    case People = 'people';
    case Opportunity = 'opportunity';
    case Task = 'task';
    case Note = 'note';
    case Email = 'emails';
    case EmailDraft = 'email_drafts';
    case EmailDraftSend = 'email_draft_sends';
    case EmailDraftDelete = 'email_draft_deletes';
    case EmailCancel = 'email_cancels';
    case EmailReschedule = 'email_reschedules';
    case EmailRetry = 'email_retries';
    case WorkspaceInvitation = 'workspace_invitations';
    case CustomField = 'custom_field';
    case SampleData = 'sample_data';
    case WebAccess = 'web_access';

    public function singularName(): string
    {
        return match ($this) {
            self::Company => CrmEntity::Company->singularName(),
            self::People => CrmEntity::People->singularName(),
            self::Opportunity => CrmEntity::Opportunity->singularName(),
            self::Task => CrmEntity::Task->singularName(),
            self::Note => CrmEntity::Note->singularName(),
            self::Email => 'Email',
            self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete => 'Email Draft',
            self::EmailCancel, self::EmailReschedule, self::EmailRetry => 'Email',
            self::WorkspaceInvitation => 'Teammate',
            self::CustomField => 'Custom Field',
            self::SampleData => 'Sample Data',
            self::WebAccess => 'Web Search',
        };
    }

    public function titleKey(): string
    {
        return match ($this) {
            self::Company => CrmEntity::Company->titleColumn(),
            self::People => CrmEntity::People->titleColumn(),
            self::Opportunity => CrmEntity::Opportunity->titleColumn(),
            self::Task => CrmEntity::Task->titleColumn(),
            self::Note => CrmEntity::Note->titleColumn(),
            self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry => 'subject',
            self::WorkspaceInvitation => 'email',
            self::CustomField, self::SampleData => 'name',
            self::WebAccess => 'query',
        };
    }

    public function titleLabel(): string
    {
        return match ($this) {
            self::Company, self::People, self::Opportunity, self::CustomField, self::SampleData => 'Name',
            self::Task, self::Note => 'Title',
            self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry => 'Subject',
            self::WorkspaceInvitation => 'Email',
            self::WebAccess => 'Search',
        };
    }

    /** @return list<string> */
    public function coreKeys(): array
    {
        return match ($this) {
            self::Company => [$this->titleKey(), 'account_owner_id'],
            self::WorkspaceInvitation => [$this->titleKey(), 'role'],
            self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::CustomField, self::SampleData, self::WebAccess => [$this->titleKey()],
        };
    }

    public function isCore(string $code): bool
    {
        return in_array($code, $this->coreKeys(), true);
    }

    public function isIndivisible(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WebAccess => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::WorkspaceInvitation, self::CustomField, self::SampleData => false,
        };
    }

    public function needsOwnApproval(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraftSend, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation, self::WebAccess => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftDelete, self::CustomField, self::SampleData => false,
        };
    }

    public function approvesFromKeyboard(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraftSend, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation => false,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftDelete, self::CustomField, self::SampleData, self::WebAccess => true,
        };
    }

    public function createTitle(): string
    {
        return Str::ucfirst($this->verb(PendingActionOperation::Create)).' '.$this->singularName();
    }

    public function createSummary(string $title): string
    {
        return match ($this) {
            self::WorkspaceInvitation => "Invite \"{$title}\"",
            self::WebAccess => "Search the web for \"{$title}\"",
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::CustomField, self::SampleData => Str::ucfirst($this->verb(PendingActionOperation::Create)).' '.Str::lower($this->singularName())." \"{$title}\"",
        };
    }

    public function action(PendingActionOperation $operation, int $count = 1): string
    {
        return match ($this) {
            self::Email => __('Send'),
            self::EmailDraft => __('Save draft'),
            self::EmailDraftSend => __('Send'),
            self::EmailDraftDelete => __('Delete draft'),
            self::EmailCancel => __('Cancel email'),
            self::EmailReschedule => __('Reschedule'),
            self::EmailRetry => __('Send again'),
            self::WorkspaceInvitation => trans_choice('Send invitation|Send :count invitations', $count, ['count' => $count]),
            self::WebAccess => __('Allow'),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->action(),
        };
    }

    public function deletes(PendingActionOperation $operation): bool
    {
        return match ($this) {
            self::EmailDraftDelete => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation, self::CustomField, self::SampleData, self::WebAccess => $operation === PendingActionOperation::Delete,
        };
    }

    public function confirmLabel(PendingActionOperation $operation, int $count = 1): string
    {
        return match ($this) {
            self::Email => __('Send email'),
            self::EmailDraftDelete => trans_choice('Delete draft|Delete :count drafts', $count, ['count' => $count]),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation === PendingActionOperation::Delete
                ? __('Delete :count :entities', ['count' => $count, 'entities' => Str::plural(Str::lower($this->singularName()), $count)])
                : __('Approve'),
            self::EmailDraft => __('Approve'),
            self::EmailDraftSend, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation, self::WebAccess => $this->action($operation, $count),
        };
    }

    public function declineLabel(): string
    {
        return match ($this) {
            self::Email, self::EmailDraftSend, self::WorkspaceInvitation => __("Don't send"),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::CustomField, self::SampleData, self::WebAccess => __('Discard'),
        };
    }

    public function followUpLabel(): string
    {
        return match ($this) {
            self::Email => __('Then email'),
            self::EmailDraftSend => __('Then send draft'),
            self::EmailCancel => __('Then cancel email'),
            self::EmailReschedule => __('Then reschedule email'),
            self::EmailRetry => __('Then send again'),
            self::WorkspaceInvitation => __('Then invite'),
            self::WebAccess => __('Then search the web'),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftDelete, self::CustomField, self::SampleData => __('Then'),
        };
    }

    public function done(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => __('Sent'),
            self::EmailDraft => __('Saved'),
            self::EmailDraftSend => __('Sent'),
            self::EmailDraftDelete => __('Deleted'),
            self::EmailCancel => __('Cancelled'),
            self::EmailReschedule => __('Rescheduled'),
            self::EmailRetry => __('Queued again'),
            self::WorkspaceInvitation => __('Invited'),
            self::WebAccess => __('Web search allowed'),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->done(),
        };
    }

    public function verb(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => 'send',
            self::EmailDraft => 'save',
            self::EmailDraftSend => 'send',
            self::EmailDraftDelete => 'delete',
            self::EmailCancel => 'cancel',
            self::EmailReschedule => 'reschedule',
            self::EmailRetry => 'retry',
            self::WorkspaceInvitation => 'invite',
            self::WebAccess => 'search',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->verb(),
        };
    }

    public function notDone(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => 'NOT sent',
            self::EmailDraft => 'NOT saved',
            self::EmailDraftSend => 'NOT sent',
            self::EmailDraftDelete => 'NOT deleted',
            self::EmailCancel => 'NOT cancelled, so it still goes out',
            self::EmailReschedule => 'NOT rescheduled',
            self::EmailRetry => 'NOT queued again',
            self::WorkspaceInvitation => 'NOT invited',
            self::WebAccess => 'NOT allowed, so answer without the web',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->notDone(),
        };
    }

    public function noun(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete => 'email draft',
            self::EmailCancel, self::EmailReschedule => 'queued email',
            self::EmailRetry => 'failed email',
            self::WorkspaceInvitation => 'teammate',
            self::WebAccess => 'the web for',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $this->value,
        };
    }

    public function decisionWord(PendingActionStatus $status): string
    {
        return match ($this) {
            self::WebAccess => match ($status) {
                PendingActionStatus::Pending => 'PENDING',
                PendingActionStatus::Approved => 'ALLOWED (web search for the resumed turn only, not a write)',
                PendingActionStatus::Rejected => 'DENIED (no web for that request, not a write)',
                PendingActionStatus::Expired, PendingActionStatus::Superseded => 'EXPIRED (no answer, no web for that request, not a write)',
            },
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation, self::CustomField, self::SampleData => $status->promptWord(),
        };
    }

    public function receiptVerb(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Task => $operation === PendingActionOperation::Create ? __('Created task') : $operation->done(),
            self::Note => $operation === PendingActionOperation::Create ? __('Added note') : $operation->done(),
            self::Email => __('Queued email to'),
            self::Company, self::People, self::Opportunity, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation, self::CustomField, self::SampleData, self::WebAccess => $this->done($operation),
        };
    }

    public function receiptTarget(): ?string
    {
        return match ($this) {
            self::Email => 'To',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation, self::CustomField, self::SampleData, self::WebAccess => null,
        };
    }

    public function declinedOutcome(): ProposalOutcome
    {
        return match ($this) {
            self::Email, self::EmailDraftSend, self::WorkspaceInvitation => ProposalOutcome::NotSent,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::CustomField, self::SampleData, self::WebAccess => ProposalOutcome::LeftOut,
        };
    }

    private function receiptBatch(PendingActionOperation $operation, int $count): string
    {
        return match ($this) {
            self::WorkspaceInvitation => $count === 1
                ? __('Sent :count invitation', ['count' => ':count'])
                : __('Sent :count invitations', ['count' => ':count']),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::CustomField, self::SampleData, self::WebAccess => __(':verb :count :entities', [
                'verb' => $operation->done(),
                'count' => ':count',
                'entities' => Str::plural(Str::lower($this->singularName()), $count),
            ]),
        };
    }

    private function receiptMoved(int $count): string
    {
        return __('Moved :count :entities to :value', [
            'count' => ':count',
            'entities' => Str::plural(Str::lower($this->singularName()), $count),
            'value' => ':value',
        ]);
    }

    /**
     * @return array<string, array{
     *     verbs: array<string, string>,
     *     batch: array<string, array{one: string, other: string}>,
     *     moved: array{one: string, other: string},
     *     target: ?string,
     *     title: string,
     *     declined: string
     * }>
     */
    public static function receipts(): array
    {
        $receipts = [];

        foreach (self::cases() as $entity) {
            $receipts[$entity->value] = [
                'verbs' => collect(PendingActionOperation::cases())
                    ->mapWithKeys(fn (PendingActionOperation $operation): array => [$operation->value => $entity->receiptVerb($operation)])
                    ->all(),
                'batch' => collect(PendingActionOperation::cases())
                    ->mapWithKeys(fn (PendingActionOperation $operation): array => [$operation->value => [
                        'one' => $entity->receiptBatch($operation, 1),
                        'other' => $entity->receiptBatch($operation, 2),
                    ]])
                    ->all(),
                'moved' => [
                    'one' => $entity->receiptMoved(1),
                    'other' => $entity->receiptMoved(2),
                ],
                'target' => $entity->receiptTarget(),
                'title' => $entity->titleLabel(),
                'declined' => $entity->declinedOutcome()->value,
            ];
        }

        return $receipts;
    }
}
