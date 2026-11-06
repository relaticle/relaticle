<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Jobs\RecomputeRecordEmailMetricsJob;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class UnlinkEmailFromRecord
{
    public function __construct(
        private PrepareEmailRecordLink $prepare,
        private ConversationsQuery $conversations,
    ) {}

    public function execute(User $user, Email $email, Company|People|Opportunity $record): void
    {
        $conversationKey = filled($email->conversation_key) ? (string) $email->conversation_key : null;

        $previousAudience = DB::transaction(function () use ($user, $email, $record, $conversationKey): array {
            $ids = $this->prepare->execute($user, $email, $record)->modelKeys();
            $previousAudience = $conversationKey !== null && ConversationChanged::broadcasts()
                ? $this->conversations->audience($email->workspace_id, $conversationKey)
                : [];

            $record->emails()->detach($ids);

            $now = now();

            EmailLinkSuppression::query()->insertOrIgnore(array_map(fn (string $id): array => [
                'id' => (new EmailLinkSuppression)->newUniqueId(),
                'workspace_id' => $email->workspace_id,
                'email_id' => $id,
                'emailable_type' => $record->getMorphClass(),
                'emailable_id' => $record->getKey(),
                'user_id' => $user->getKey(),
                'created_at' => $now,
            ], $ids));

            return $previousAudience;
        });

        dispatch(new RecomputeRecordEmailMetricsJob($record))->afterCommit();

        if ($conversationKey !== null) {
            event(new ConversationChanged($email->workspace_id, $conversationKey, $previousAudience));
        }
    }
}
