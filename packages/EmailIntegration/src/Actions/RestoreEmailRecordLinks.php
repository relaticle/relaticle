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

final readonly class RestoreEmailRecordLinks
{
    public function __construct(
        private PrepareEmailRecordLink $prepare,
        private LinkEmailAction $linkEmail,
    ) {}

    /**
     * Undoes one unlink: lifts the suppressions it wrote and puts back the links it took off, with their source.
     * The user's other copies of the conversation then link again by the sync rules, so a reply synced since stays in step.
     *
     * @param  array<array-key, mixed>  $changed  what UnlinkEmailFromRecord returned
     * @return bool whether it lifted or restored anything
     */
    public function execute(User $user, Email $email, Company|People|Opportunity $record, array $changed): bool
    {
        [$restored, $others] = DB::transaction(function () use ($user, $email, $record, $changed): array {
            $copies = $this->prepare->execute($user, $email, $record);
            $ids = $this->liftSuppressions($email, $record, array_values(array_intersect(array_map(strval(...), array_keys($changed)), $copies->modelKeys())));
            $linked = DB::table('emailables')
                ->whereIn('email_id', $ids)
                ->where('emailable_type', $record->getMorphClass())
                ->where('emailable_id', $record->getKey())
                ->pluck('email_id')
                ->all();

            foreach (array_diff($ids, $linked) as $id) {
                $source = $changed[$id] ?? null;

                if (in_array($source, ['auto', 'manual'], true)) {
                    $record->emails()->attach($id, ['link_source' => $source]);
                }
            }

            $others = $copies
                ->filter(fn (Email $copy): bool => $ids !== [] && $copy->user_id === $user->getKey() && ! in_array($copy->getKey(), $ids, true))
                ->load(['connectedAccount', 'workspace']);

            return [$ids !== [], $others];
        });

        if (! $restored) {
            return false;
        }

        foreach ($others as $copy) {
            $this->linkEmail->reapply($copy);
        }

        dispatch(new RecomputeRecordEmailMetricsJob($record))->afterCommit();

        if (filled($email->conversation_key)) {
            event(new ConversationChanged($email->workspace_id, (string) $email->conversation_key));
        }

        return true;
    }

    /**
     * @param  list<string>  $emailIds
     * @return list<string> the copies whose suppression of the record it deleted
     */
    private function liftSuppressions(Email $email, Company|People|Opportunity $record, array $emailIds): array
    {
        $suppressed = EmailLinkSuppression::query()
            ->where('workspace_id', $email->workspace_id)
            ->whereIn('email_id', $emailIds)
            ->where('emailable_type', $record->getMorphClass())
            ->where('emailable_id', $record->getKey());
        $ids = array_values(array_map(strval(...), $suppressed->pluck('email_id')->all()));
        $suppressed->delete();

        return $ids;
    }
}
