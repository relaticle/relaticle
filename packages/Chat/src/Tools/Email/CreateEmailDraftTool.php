<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\Chat\Tools\Concerns\DescribesEmailProposals;
use Relaticle\EmailIntegration\Actions\PrepareAgentEmailDraft;
use Relaticle\EmailIntegration\Actions\SaveAssistantEmailDraft;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;

final class CreateEmailDraftTool extends BaseWriteCreateTool
{
    use DescribesEmailProposals;

    public function description(): string
    {
        return 'Propose saving an email draft in one of the user\'s mailboxes. Returns a proposal the user approves; nothing is sent.'
            .' Once approved, the user reviews the draft and sends it from Drafts.'
            .' Write `body` as plain text or markdown. The mailbox signature is added unless `include_signature` is false.';
    }

    protected function actionClass(): string
    {
        return SaveAssistantEmailDraft::class;
    }

    protected function entityType(): string
    {
        return 'email_drafts';
    }

    protected function nameAttribute(): string
    {
        return 'subject';
    }

    protected function ownedForeignKeys(): array
    {
        return [];
    }

    protected function requiredCapability(): ?WorkspaceCapability
    {
        return null;
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'connected_account_id' => $schema->string()->description('The mailbox to draft in, from the list email accounts tool.')->required(),
            'to' => $schema->array()->items($schema->string())->description('Recipient email addresses.'),
            'cc' => $schema->array()->items($schema->string())->description('CC email addresses.'),
            'bcc' => $schema->array()->items($schema->string())->description('BCC email addresses.'),
            'subject' => $schema->string()->description('Subject line, up to 255 characters. A draft needs one.')->required(),
            'body' => $schema->string()->description('The message as plain text or markdown. Raw HTML is escaped.'),
            'include_signature' => $schema->boolean()->description('Add the mailbox default signature.')->default(true),
            'in_reply_to_email_id' => $schema->string()->description('ID of the email this draft replies to, so it threads.'),
        ];
    }

    protected function extractRecordData(array $record): array
    {
        return $this->emailData($record);
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        $data = $this->emailData($record);

        return $this->emailError($data, PrepareAgentEmailDraft::RULES, function () use ($data, $user): array {
            /** @var array{connected_account_id: string, to?: list<string>, cc?: list<string>, bcc?: list<string>, subject?: string, body?: string, include_signature: bool, in_reply_to_email_id?: string} $data */
            return resolve(PrepareAgentEmailDraft::class)->execute($user, $data, EmailCreationSource::CHAT);
        });
    }

    protected function buildRecordDisplay(array $record): array
    {
        return [
            'title' => 'Save Email Draft',
            'summary' => $this->emailSummary('Save email draft', $record),
            'fields' => $this->emailRows($record),
        ];
    }
}
