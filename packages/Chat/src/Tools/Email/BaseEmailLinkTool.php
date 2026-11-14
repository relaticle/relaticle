<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Enums\CrmEntity;
use App\Enums\WorkspaceCapability;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\EmailLinkTargetsQuery;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;

abstract class BaseEmailLinkTool extends BaseWriteCreateTool
{
    abstract protected function recordsDescription(): string;

    abstract protected function scopeDescription(): string;

    protected function nameAttribute(): string
    {
        return 'email_id';
    }

    protected function requiredCapability(): ?WorkspaceCapability
    {
        return WorkspaceCapability::RecordsUpdate;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'records' => $schema->array()
                ->items($schema->object($this->entitySchema($schema)))
                ->required()
                ->description($this->recordsDescription().' Pass up to '.config('chat.max_batch_size').' items in ONE call (never loop one call per email). Each email moves with the other copies of its conversation the user holds or was given in full.'),
        ];
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'email_id' => $schema->string()->description('The email ID, as listed by the list emails tool.')->required(),
            'record_type' => $schema->string()->enum(EmailLinkTargetsQuery::types())->description('The kind of record.')->required(),
            'record_id' => $schema->string()->description('The record ID, as returned by the list, get or search tools. A record proposed earlier in this turn cannot be used until it is approved.')->required(),
        ];
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        if (! $this->recordEmail($record) instanceof Email) {
            return 'No email you can see has that ID. List emails to find it.';
        }

        $target = $this->target($record);

        if ($target === null) {
            return 'No company, person or opportunity in this workspace has that record_type and record_id.';
        }

        return $user->can('update', $target) ? null : 'Your role cannot change that record.';
    }

    protected function extractRecordData(array $record): array
    {
        return [
            'email_id' => (string) $record['email_id'],
            'record_type' => (string) ($record['record_type'] ?? ''),
            'record_id' => (string) ($record['record_id'] ?? ''),
            'subject' => $this->subjectOf($this->recordEmail($record)),
        ];
    }

    protected function buildRecordDisplay(array $record): array
    {
        $data = $this->extractRecordData($record);
        $target = $this->target($record);
        $entity = $this->proposalEntity();
        $recordLabel = $target === null ? '' : CrmEntity::from($data['record_type'])->singularName().': '.$target->name;

        return [
            'title' => $entity->createTitle(),
            'summary' => $entity->action(PendingActionOperation::Create).": {$data['subject']}, {$recordLabel}",
            'fields' => [
                ['label' => 'Subject', 'value' => $data['subject']],
                ['label' => 'Record', 'value' => $recordLabel],
                ['label' => 'Scope', 'value' => $this->scopeDescription()],
            ],
        ];
    }

    /** @param  array<string, mixed>  $record */
    private function recordEmail(array $record): ?Email
    {
        $id = $record['email_id'] ?? null;

        return is_string($id) && $id !== '' ? resolve(VisibleEmailsQuery::class)->find($this->user(), $id) : null;
    }

    /** @param  array<string, mixed>  $record */
    private function target(array $record): Company|People|Opportunity|null
    {
        $type = $record['record_type'] ?? null;
        $id = $record['record_id'] ?? null;

        return is_string($type) && is_string($id) ? resolve(EmailLinkTargetsQuery::class)->find($this->user(), $type, $id) : null;
    }

    private function subjectOf(?Email $email): string
    {
        if (! $email instanceof Email || ! $this->user()->can('viewSubject', $email)) {
            return 'Subject hidden';
        }

        return filled($email->subject) ? (string) $email->subject : '(no subject)';
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
