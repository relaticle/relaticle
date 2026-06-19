<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailThread;

final readonly class SyncEmailThreadAction
{
    /**
     * Create or refresh the EmailThread aggregate for a provider thread.
     * Recomputes counts and date range from the thread's stored emails so the
     * row stays accurate as new messages arrive.
     */
    public function execute(ConnectedAccount $connectedAccount, string $threadId): ?EmailThread
    {
        $emails = Email::query()
            ->where('connected_account_id', $connectedAccount->getKey())
            ->where('thread_id', $threadId)
            ->whereNotNull('sent_at')
            ->oldest('sent_at')
            ->get(['id', 'subject', 'sent_at']);

        if ($emails->isEmpty()) {
            return null;
        }

        $participantCount = EmailParticipant::query()
            ->whereIn('email_id', $emails->modelKeys())
            ->distinct()
            ->count('email_address');

        $now = now();

        // Atomic upsert (INSERT ... ON CONFLICT DO UPDATE) rather than updateOrCreate:
        // StoreEmailJob runs one job per message, so parallel workers can sync the same
        // thread concurrently. A SELECT-then-INSERT would race on the unique
        // (connected_account_id, thread_id) index — and the resulting violation would
        // abort StoreEmailAction's surrounding transaction. A single statement avoids both.
        EmailThread::query()->upsert(
            [[
                'id' => (string) Str::ulid(),
                'connected_account_id' => $connectedAccount->getKey(),
                'thread_id' => $threadId,
                'team_id' => $connectedAccount->team_id,
                'subject' => $emails->first()->subject,
                'email_count' => $emails->count(),
                'participant_count' => $participantCount,
                'first_email_at' => $emails->first()->sent_at,
                'last_email_at' => $emails->last()->sent_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['connected_account_id', 'thread_id'],
            ['subject', 'email_count', 'participant_count', 'first_email_at', 'last_email_at', 'updated_at'],
        );

        return EmailThread::query()
            ->where('connected_account_id', $connectedAccount->getKey())
            ->where('thread_id', $threadId)
            ->first();
    }
}
