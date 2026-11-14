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

    /**
     * The copies this unlink changed: each one's link source before, or null for a copy it only suppressed.
     *
     * @return array<string, 'auto'|'manual'|null>
     */
    public function execute(User $user, Email $email, Company|People|Opportunity $record): array
    {
        $conversationKey = filled($email->conversation_key) ? (string) $email->conversation_key : null;

        $previousAudience = $conversationKey !== null && ConversationChanged::broadcasts()
            ? $this->conversations->audience($email->workspace_id, $conversationKey)
            : [];

        $changed = DB::transaction(function () use ($user, $email, $record): array {
            $ids = $this->prepare->execute($user, $email, $record)->modelKeys();
            $changed = $this->changedCopies($email, $record, array_values(array_map(strval(...), $ids)));

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

            return $changed;
        });

        dispatch(new RecomputeRecordEmailMetricsJob($record))->afterCommit();

        if ($conversationKey !== null) {
            event(new ConversationChanged($email->workspace_id, $conversationKey, $previousAudience));
        }

        return $changed;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, 'auto'|'manual'|null>
     */
    private function changedCopies(Email $email, Company|People|Opportunity $record, array $ids): array
    {
        $linked = DB::table('emailables')
            ->whereIn('email_id', $ids)
            ->where('emailable_type', $record->getMorphClass())
            ->where('emailable_id', $record->getKey())
            ->pluck('link_source', 'email_id')
            ->all();
        $suppressed = EmailLinkSuppression::query()
            ->where('workspace_id', $email->workspace_id)
            ->whereIn('email_id', $ids)
            ->where('emailable_type', $record->getMorphClass())
            ->where('emailable_id', $record->getKey())
            ->pluck('email_id')
            ->all();
        $changed = array_fill_keys(array_values(array_diff($ids, $suppressed)), null);

        foreach ($linked as $id => $source) {
            $changed[(string) $id] = $source === 'manual' ? 'manual' : 'auto';
        }

        return $changed;
    }
}
