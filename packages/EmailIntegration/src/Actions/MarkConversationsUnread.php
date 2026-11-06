<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\TriageSkipReason;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class MarkConversationsUnread
{
    public function __construct(
        private PrepareConversationTriage $prepare,
        private ConversationsQuery $conversations,
    ) {}

    /** @param  array<mixed>  $conversationKeys */
    public function execute(User $user, array $conversationKeys): TriageOutcome
    {
        $outcome = $this->prepare->execute($user, $conversationKeys);
        // Unread state lives on inbound mail, so clearing the newest reached inbound copy is enough.
        $newest = $this->conversations->reachedCopies($user, $outcome->appliedKeys)
            ->where('direction', EmailDirection::INBOUND->value)
            ->unique('conversation_key');
        $outcome = $outcome->skipping(
            TriageSkipReason::NotInYourMailbox,
            array_values(array_diff($outcome->appliedKeys, $newest->pluck('conversation_key')->all())),
        );

        if ($newest->isNotEmpty()) {
            EmailRead::query()
                ->where('user_id', $user->getKey())
                ->whereIn('email_id', $newest->pluck('id')->all())
                ->delete();
        }

        return $outcome;
    }
}
