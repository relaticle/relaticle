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
    public function __construct(private PrepareEmailRecordLink $prepare) {}

    /**
     * Undoes one unlink: lifts the suppressions it wrote and puts back the links it took off, with their source.
     *
     * @param  array<array-key, mixed>  $changed  what UnlinkEmailFromRecord returned
     */
    public function execute(User $user, Email $email, Company|People|Opportunity $record, array $changed): void
    {
        DB::transaction(function () use ($user, $email, $record, $changed): void {
            $reached = $this->prepare->execute($user, $email, $record)->modelKeys();
            $suppressed = EmailLinkSuppression::query()
                ->where('workspace_id', $email->workspace_id)
                ->whereIn('email_id', array_values(array_intersect(array_map(strval(...), array_keys($changed)), $reached)))
                ->where('emailable_type', $record->getMorphClass())
                ->where('emailable_id', $record->getKey());
            $ids = $suppressed->pluck('email_id')->all();
            $suppressed->delete();

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
        });

        dispatch(new RecomputeRecordEmailMetricsJob($record))->afterCommit();

        if (filled($email->conversation_key)) {
            event(new ConversationChanged($email->workspace_id, (string) $email->conversation_key));
        }
    }
}
