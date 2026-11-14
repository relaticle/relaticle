<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\EmailIntegration\Actions\RequestAssistantEmailAccess;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;

final class RequestEmailAccessTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose asking mailbox owners for access to visible locked emails. Each request names the access level and optional note. The user approves the proposal before owners are notified. Private emails absent from the list cannot be requested.';
    }

    protected function actionClass(): string
    {
        return RequestAssistantEmailAccess::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailAccessRequest->value;
    }

    protected function nameAttribute(): string
    {
        return 'email_id';
    }

    protected function requiredCapability(): ?WorkspaceCapability
    {
        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['records' => $schema->array()->items($schema->object($this->entitySchema($schema)))->required()
            ->description('Emails to request access to, in one proposal. Owners decide each request after the user approves.')];
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'email_id' => $schema->string()->description('A visible locked email ID from the email tools.')->required(),
            'tier_requested' => $schema->string()->enum(['subject', 'full'])->description('Subject only, or full message. Defaults to full.'),
            'note' => $schema->string()->max(EmailAccessRequest::NOTE_MAX_LENGTH)->description('Optional reason the mailbox owner will see.'),
        ];
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        $email = $this->email($record);

        if (! $email instanceof Email || ! $user->can('requestAccess', $email)) {
            return 'Choose a visible email whose subject or message is locked.';
        }

        if (! in_array($record['tier_requested'] ?? 'full', ['subject', 'full'], true)
            || (isset($record['note']) && (! is_string($record['note']) || mb_strlen($record['note']) > EmailAccessRequest::NOTE_MAX_LENGTH))) {
            return 'Choose subject or full access and a note no longer than 500 characters.';
        }

        return $email->hasPendingAccessRequestFrom($user) ? 'Access has already been requested for this email.' : null;
    }

    protected function extractRecordData(array $record): array
    {
        return [
            'email_id' => $record['email_id'],
            'tier_requested' => $record['tier_requested'] ?? 'full',
            'note' => $record['note'] ?? null,
            'subject' => $this->subject($this->email($record)),
        ];
    }

    protected function buildRecordDisplay(array $record): array
    {
        $email = $this->email($record);
        $subject = $this->subject($email);

        return [
            'title' => 'Request email access',
            'summary' => 'Request access: '.$subject,
            'fields' => [
                ['label' => 'Mailbox owner', 'value' => $email?->mailboxViaName() ?? 'Unknown owner'],
                ['label' => 'Subject', 'value' => $subject],
                ['label' => 'Access', 'value' => EmailPrivacyTier::from($record['tier_requested'] ?? 'full')->getLabel()],
                ['label' => 'Note', 'value' => $record['note'] ?? ''],
            ],
        ];
    }

    /** @param array<string, mixed> $record */
    private function email(array $record): ?Email
    {
        $id = $record['email_id'] ?? null;

        return is_string($id) ? resolve(VisibleEmailsQuery::class)->find($this->user(), $id) : null;
    }

    private function subject(?Email $email): string
    {
        return $email instanceof Email && $this->user()->can('viewSubject', $email)
            ? ($email->subject ?: '(no subject)')
            : 'Subject hidden';
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
