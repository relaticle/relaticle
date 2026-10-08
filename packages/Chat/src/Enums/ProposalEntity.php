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
            self::EmailDraft => 'Email Draft',
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
            self::Email, self::EmailDraft => 'subject',
            self::WorkspaceInvitation => 'email',
            self::CustomField, self::SampleData => 'name',
        };
    }

    public function titleLabel(): string
    {
        return match ($this) {
            self::Company, self::People, self::Opportunity, self::CustomField, self::SampleData => 'Name',
            self::Task, self::Note => 'Title',
            self::Email, self::EmailDraft => 'Subject',
            self::WorkspaceInvitation => 'Email',
        };
    }

    /** @return list<string> */
    public function coreKeys(): array
    {
        return match ($this) {
            self::Company => [$this->titleKey(), 'account_owner_id'],
            self::WorkspaceInvitation => [$this->titleKey(), 'role'],
            self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::CustomField, self::SampleData => [$this->titleKey()],
        };
    }

    public function isCore(string $code): bool
    {
        return in_array($code, $this->coreKeys(), true);
    }

    public function isIndivisible(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraft => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::WorkspaceInvitation, self::CustomField, self::SampleData => false,
        };
    }

    public function needsOwnApproval(): bool
    {
        return match ($this) {
            self::Email, self::WorkspaceInvitation => true,
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::EmailDraft, self::CustomField, self::SampleData => false,
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
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::Email, self::EmailDraft, self::CustomField, self::SampleData => Str::ucfirst($this->verb(PendingActionOperation::Create)).' '.Str::lower($this->singularName())." \"{$title}\"",
        };
    }

    public function action(PendingActionOperation $operation, int $count = 1): string
    {
        return match ($this) {
            self::Email => __('Send'),
            self::EmailDraft => __('Save draft'),
            self::WorkspaceInvitation => trans_choice('Send invitation|Send :count invitations', $count, ['count' => $count]),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->action(),
        };
    }

    public function done(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => __('Sent'),
            self::EmailDraft => __('Saved'),
            self::WorkspaceInvitation => __('Invited'),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->done(),
        };
    }

    public function count(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => __(':count sent'),
            self::EmailDraft => __(':count saved'),
            self::WorkspaceInvitation => __(':count invited'),
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->count(),
        };
    }

    public function verb(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => 'send',
            self::EmailDraft => 'save',
            self::WorkspaceInvitation => 'invite',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->verb(),
        };
    }

    public function notDone(PendingActionOperation $operation): string
    {
        return match ($this) {
            self::Email => 'NOT sent',
            self::EmailDraft => 'NOT saved',
            self::WorkspaceInvitation => 'NOT invited',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $operation->notDone(),
        };
    }

    public function noun(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::EmailDraft => 'email draft',
            self::WorkspaceInvitation => 'teammate',
            self::Company, self::People, self::Opportunity, self::Task, self::Note, self::CustomField, self::SampleData => $this->value,
        };
    }

    private function hasOwnReceipt(): bool
    {
        return match ($this) {
            self::Email, self::EmailDraft, self::WorkspaceInvitation => true,
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
