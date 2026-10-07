<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\Chat\Tools\Concerns\DescribesEmailProposals;
use Relaticle\EmailIntegration\Actions\PrepareAgentEmail;
use Relaticle\EmailIntegration\Actions\SendAssistantEmail;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Support\AgentEmailBody;

final class SendEmailTool extends BaseWriteCreateTool
{
    use DescribesEmailProposals;

    public function description(): string
    {
        return 'Propose sending an email as the user from one of their mailboxes. Returns a proposal the user approves; nothing is sent until they do.'
            .' The email reaches people outside the workspace, so propose it only when the user asked for it.'
            .' After approval it waits a few seconds so the user can still undo it.'
            .' One call proposes exactly one email: make one call per email, and each gets its own approval.'
            .' Write `body` as plain text or markdown. Prefer the create email draft tool when the user wants to review the email before it can go.';
    }

    protected function actionClass(): string
    {
        return SendAssistantEmail::class;
    }

    protected function entityType(): string
    {
        return 'emails';
    }

    protected function nameAttribute(): string
    {
        return 'subject';
    }

    protected function ownedForeignKeys(): array
    {
        return [];
    }

    protected function requiredCapability(): WorkspaceCapability
    {
        return WorkspaceCapability::EmailAgentSend;
    }

    protected function maxEmailsPerCall(): int
    {
        return 1;
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'connected_account_id' => $schema->string()->description('The mailbox to send from, from the list email accounts tool. It must have can_send true.')->required(),
            'to' => $schema->array()->items($schema->string())->description('Recipient email addresses. At most '.PrepareAgentEmail::MAX_RECIPIENTS.' recipients in total across to, cc and bcc.')->required(),
            'cc' => $schema->array()->items($schema->string())->description('CC email addresses.'),
            'bcc' => $schema->array()->items($schema->string())->description('BCC email addresses.'),
            'subject' => $schema->string()->description('Subject line, up to 255 characters.')->required(),
            'body' => $schema->string()->description('The message as plain text or markdown. Raw HTML is escaped.')->required(),
            'include_signature' => $schema->boolean()->description('Add the mailbox default signature.')->default(true),
            'in_reply_to_email_id' => $schema->string()->description('ID of the email this replies to, so it threads.'),
        ];
    }

    protected function appendedSignature(ConnectedAccount $account, array $data): ?EmailSignature
    {
        return resolve(AgentEmailBody::class)->signatureForSending($account, $data['include_signature'] === true);
    }

    protected function extractRecordData(array $record): array
    {
        return $this->emailData($record);
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        $data = $this->emailData($record);

        return $this->emailError($data, PrepareAgentEmail::RULES, function () use ($data, $user): array {
            /** @var array{connected_account_id: string, to: list<string>, cc?: list<string>, bcc?: list<string>, subject: string, body: string, include_signature: bool, in_reply_to_email_id?: string} $data */
            return resolve(PrepareAgentEmail::class)->execute($user, $data, EmailCreationSource::CHAT);
        });
    }

    protected function buildRecordDisplay(array $record): array
    {
        return [
            'title' => 'Send Email',
            'summary' => $this->emailSummary('Send email', $record),
            'fields' => $this->emailRows($record),
        ];
    }
}
