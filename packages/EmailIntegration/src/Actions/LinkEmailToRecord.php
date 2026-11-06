<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLinkSuppression;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;

final readonly class LinkEmailToRecord
{
    public function __construct(
        private PrepareEmailRecordLink $prepare,
        private RecordCommunicationMetrics $metrics,
    ) {}

    public function execute(User $user, Email $email, Company|People|Opportunity $record): void
    {
        $copies = $this->prepare->execute($user, $email, $record);
        $ids = $copies->modelKeys();

        DB::transaction(function () use ($record, $copies, $ids): void {
            $changes = $record->emails()->syncWithoutDetaching(array_fill_keys($ids, ['link_source' => 'manual']));

            EmailLinkSuppression::query()
                ->whereIn('email_id', $ids)
                ->where('emailable_type', $record->getMorphClass())
                ->where('emailable_id', $record->getKey())
                ->delete();

            foreach ($copies->only($changes['attached']) as $copy) {
                $this->metrics->incrementEmailMetrics($record, $copy);
            }
        });

        if (filled($email->conversation_key)) {
            event(new ConversationChanged($email->workspace_id, (string) $email->conversation_key));
        }
    }
}
