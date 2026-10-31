<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class UpdateEmailDraftTool extends BaseEmailDraftTool
{
    public function description(): string
    {
        return 'Propose rewriting one of the user\'s saved drafts. Returns a proposal the user approves; nothing is sent.'
            .' The draft is replaced as a whole, so pass every field it should keep: read it with the get email tool first.'
            .' Write `body` as plain text or markdown. The mailbox signature is added unless `include_signature` is false.';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'draft_id' => $schema->string()->description('The draft ID, as listed by the list emails tool with status draft.')->required(),
            ...parent::entitySchema($schema),
        ];
    }

    protected function extractRecordData(array $record): array
    {
        return [...parent::extractRecordData($record), 'draft_id' => (string) ($record['draft_id'] ?? '')];
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        $draft = resolve(ComposedEmailsQuery::class)->draft($user, (string) ($record['draft_id'] ?? ''));

        return match ($draft?->creation_source) {
            null => 'No draft of yours has that ID. List emails with status draft to find it.',
            EmailCreationSource::MASS_SEND => 'This draft is a mass send, which goes to each person separately. The user can edit it from Drafts in Relaticle.',
            default => parent::validateRecord($record, $user),
        };
    }

    protected function buildRecordDisplay(array $record): array
    {
        return [
            ...parent::buildRecordDisplay($record),
            'summary' => $this->emailSummary('Update email draft', $record),
        ];
    }
}
