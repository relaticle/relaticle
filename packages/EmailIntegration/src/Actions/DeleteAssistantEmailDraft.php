<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final readonly class DeleteAssistantEmailDraft
{
    public function __construct(
        private ComposedEmailsQuery $emails,
        private DeleteEmailDraftAction $deleteDraft,
    ) {}

    /** @param  array{draft_id: string}  $data */
    public function execute(User $user, array $data): Email
    {
        $draft = $this->emails->draft($user, $data['draft_id'])
            ?? throw ValidationException::withMessages(['draft_id' => 'This draft no longer exists.']);

        $this->deleteDraft->execute($user, $draft->getKey());

        return $draft;
    }
}
