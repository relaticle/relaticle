<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Enums\TriageSkipReason;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class MarkConversationsRead
{
    public function __construct(
        private PrepareConversationTriage $prepare,
        private ConversationsQuery $conversations,
    ) {}

    /** @param  array<mixed>  $conversationKeys */
    public function execute(User $user, array $conversationKeys): TriageOutcome
    {
        $outcome = $this->prepare->execute($user, $conversationKeys);
        $copies = $this->conversations->reachedCopies($user, $outcome->appliedKeys);
        $outcome = $outcome->skipping(
            TriageSkipReason::NotInYourMailbox,
            array_values(array_diff($outcome->appliedKeys, $copies->pluck('conversation_key')->all())),
        );

        $now = now();

        EmailRead::query()->insertOrIgnore($copies->map(fn (object $copy): array => [
            'id' => (new EmailRead)->newUniqueId(),
            'email_id' => $copy->id,
            'user_id' => $user->getKey(),
            'read_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $outcome;
    }
}
