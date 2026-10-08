<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Exceptions\EmptyDraft;
use Relaticle\EmailIntegration\Models\Email;

final readonly class SaveAgentEmailDraft
{
    public function __construct(
        private PrepareAgentEmailDraft $prepareDraft,
        private SaveEmailDraftAction $saveDraft,
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
    public function execute(User $user, array $data, EmailCreationSource $source): Email
    {
        $draft = $this->saveDraft->execute($user, $this->prepareDraft->execute($user, $data, $source));

        return $draft->load('connectedAccount');
    }
}
