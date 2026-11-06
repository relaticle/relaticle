<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class PrepareEmailRecordLink
{
    public function __construct(private ConversationsQuery $conversations) {}

    /** @return EloquentCollection<int, Email> */
    public function execute(User $user, Email $email, Company|People|Opportunity $record): EloquentCollection
    {
        abort_unless($user->can('view', $email) && $user->can('update', $record), 403);

        abort_unless(
            (string) $record->workspace_id === (string) $email->workspace_id
                && (string) $user->current_workspace_id === (string) $email->workspace_id
                && $user->belongsToWorkspaceId($email->workspace_id),
            403,
        );

        if (blank($email->conversation_key)) {
            return new EloquentCollection([$email]);
        }

        return $this->conversations->copies($user, (string) $email->conversation_key);
    }
}
