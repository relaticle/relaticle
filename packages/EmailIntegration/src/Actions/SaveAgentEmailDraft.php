<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Exceptions\EmptyDraft;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final readonly class SaveAgentEmailDraft
{
    public function __construct(
        private PrepareAgentEmailDraft $prepareDraft,
        private SaveEmailDraftAction $saveDraft,
        private ComposedEmailsQuery $emails,
    ) {}

    /**
     * @param  array{
     *     connected_account_id: string,
     *     to?: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject?: ?string,
     *     body?: ?string,
     *     include_signature?: bool,
     *     in_reply_to_email_id?: ?string,
     * }  $data
     *
     * @throws ValidationException
     * @throws EmptyDraft
     */
    public function execute(User $user, array $data, EmailCreationSource $source, ?string $draftId = null): Email
    {
        if ($draftId !== null) {
            $this->assertRewritable($user, $draftId);
        }

        $draft = $this->saveDraft->execute($user, $this->prepareDraft->execute($user, $data, $source), $draftId);

        return $draft->load('connectedAccount');
    }

    private function assertRewritable(User $user, string $draftId): void
    {
        $error = match ($this->emails->draft($user, $draftId)?->creation_source) {
            null => "Draft with ID [{$draftId}] not found.",
            EmailCreationSource::MASS_SEND => 'This draft is a mass send, which goes to each person separately. The user can edit it from Drafts in Relaticle.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['draft_id' => $error]);
        }
    }
}
