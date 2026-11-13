<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Notifications\EmailSendFailedNotification;

final readonly class MarkEmailsSendFailedAction
{
    public function __construct(private SyncEmailBatchCountersAction $syncBatchCounters) {}

    /**
     * @param  Collection<int, Email>  $emails
     */
    public function execute(Collection $emails, string $reason): void
    {
        $failed = $emails->reject(fn (Email $email): bool => $email->status === EmailStatus::SENT);
        $retriedBatchIds = EmailBatch::query()
            ->whereKey($failed->pluck('batch_id')->filter()->unique()->values()->all())
            ->where('status', EmailBatchStatus::Sending)
            ->pluck('id')
            ->all();

        $failed->each(fn (Email $email): bool => $email->update([
            'status' => EmailStatus::FAILED,
            'last_error' => $reason,
        ]));

        $emails->pluck('batch_id')->filter()->unique()
            ->each(fn (string $batchId) => $this->syncBatchCounters->execute($batchId, reportFailures: in_array($batchId, $retriedBatchIds, true)));

        $failed->filter(fn (Email $email): bool => $email->batch_id === null)
            ->groupBy(fn (Email $email): string => "{$email->user_id}|{$email->workspace_id}|{$email->connected_account_id}")
            ->each(function (Collection $senderEmails): void {
                /** @var Email $first */
                $first = $senderEmails->first();

                $first->user?->notify(EmailSendFailedNotification::forMailbox(
                    $first->workspace,
                    $first->connectedAccount,
                    $senderEmails->count(),
                    $senderEmails->count() === 1 ? $first->subject : null,
                    EmailSendFailedNotification::emailGroupKey((string) $first->getKey()),
                ));
            });
    }
}
