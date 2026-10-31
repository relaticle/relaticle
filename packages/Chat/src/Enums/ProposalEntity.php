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
        };
    }

    public function titleLabel(): string
    {
        return match ($this) {
            self::Company, self::People, self::Opportunity, self::CustomField, self::SampleData => 'Name',
            self::Task, self::Note => 'Title',
            self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry => 'Subject',
            self::WorkspaceInvitation => 'Email',
        };
    }

    /** @return list<string> */
    public function coreKeys(): array
    {
        return match ($this) {
            self::Company => [$this->titleKey(), 'account_owner_id'],
            self::WorkspaceInvitation => [$this->titleKey(), 'role'],
            self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::CustomField, self::SampleData => [$this->titleKey()],
        };
    }

    public function isCore(string $code): bool
    {
        return in_array($code, $this->coreKeys(), true);
    }

    public function isIndivisible(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::WorkspaceInvitation, self::CustomField, self::SampleData => false,
        };
    }

    public function needsOwnApproval(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraftSend, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::EmailDraftDelete, self::CustomField, self::SampleData => false,
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
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->action(),
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
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->done(),
        };
    }

    public function count(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => __(':count sent'),
            self::EmailDraft => __(':count saved'),
            self::EmailDraftSend => __(':count sent'),
            self::EmailDraftDelete => __(':count deleted'),
            self::EmailCancel => __(':count cancelled'),
            self::EmailReschedule => __(':count rescheduled'),
            self::EmailRetry => __(':count queued again'),
            self::WorkspaceInvitation => __(':count invited'),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->count(),
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
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $this->value,
        };
    }

    private function hasOwnReceipt(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraft, self::EmailDraftSend, self::EmailDraftDelete, self::EmailCancel, self::EmailReschedule, self::EmailRetry, self::WorkspaceInvitation => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => false,
        };
    }

    /** @return array<string, array{done: string, count: string}> */
    public static function receipts(): array
    {
        $receipts = [];

        foreach (self::cases() as $entity) {
            if (! $entity->hasOwnReceipt()) {
                continue;
            }

            $receipts[$entity->value] = [
                'done' => $entity->done(PendingActionOperation::Create),
                'count' => $entity->count(PendingActionOperation::Create),
            ];
        }

        return $receipts;
    }
}
