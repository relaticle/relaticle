<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use RuntimeException;

final readonly class SendEmailAction
{
    /**
     * Persist a queued Email row. The scheduled dispatcher releases it later.
     *
     * @param  array{
     *     connected_account_id: string,
     *     subject: string,
     *     body_html: string,
     *     to: array<array{email: string, name: ?string}>,
     *     cc?: array<array{email: string, name: ?string}>,
     *     bcc?: array<array{email: string, name: ?string}>,
     *     in_reply_to_email_id?: ?string,
     *     creation_source: EmailCreationSource,
     *     privacy_tier: EmailPrivacyTier,
     *     batch_id?: ?string,
     *     scheduled_for?: ?DateTimeInterface,
     *     priority?: EmailPriority,
     * }  $data
     * @param  class-string|null  $linkToType
     */
    public function execute(array $data, ?string $linkToType = null, ?string $linkToId = null): Email
    {
        /** @var ConnectedAccount $account */
        $account = ConnectedAccount::query()->findOrFail($data['connected_account_id']);
        $priority = $data['priority'] ?? EmailPriority::BULK;

        $this->assertUnderMaxQueued((string) $account->user_id);

        $scheduledFor = $this->resolveScheduledFor($data, $priority);

        return DB::transaction(function () use ($account, $data, $priority, $scheduledFor, $linkToType, $linkToId): Email {
            /** @var Email|null $inReplyTo */
            $inReplyTo = isset($data['in_reply_to_email_id'])
                ? Email::query()->whereKey($data['in_reply_to_email_id'])->first()
                : null;

            /** @var Email $email */
            $email = Email::query()->create([
                'team_id' => $account->team_id,
                'user_id' => $account->user_id,
                'connected_account_id' => $account->getKey(),
                'rfc_message_id' => null,
                'provider_message_id' => null,
                'thread_id' => $inReplyTo?->thread_id,
                'in_reply_to' => $inReplyTo?->rfc_message_id,
                'subject' => $data['subject'],
                'snippet' => mb_substr(strip_tags((string) $data['body_html']), 0, 255),
                'sent_at' => null,
                'scheduled_for' => $scheduledFor,
                'direction' => EmailDirection::OUTBOUND,
                'folder' => EmailFolder::Sent,
                'status' => EmailStatus::QUEUED,
                'priority' => $priority,
                'privacy_tier' => $data['privacy_tier'],
                'has_attachments' => false,
                'is_internal' => false,
                'creation_source' => $data['creation_source'],
                'batch_id' => $data['batch_id'] ?? null,
                'attempts' => 0,
            ]);

            EmailBody::query()->create([
                'email_id' => $email->getKey(),
                'body_html' => $data['body_html'],
                'body_text' => strip_tags((string) $data['body_html']),
            ]);

            EmailParticipant::query()->create([
                'email_id' => $email->getKey(),
                'email_address' => $account->email_address,
                'name' => $account->display_name,
                'role' => 'from',
            ]);

            foreach (['to', 'cc', 'bcc'] as $role) {
                foreach ($data[$role] ?? [] as $recipient) {
                    EmailParticipant::query()->create([
                        'email_id' => $email->getKey(),
                        'email_address' => $recipient['email'],
                        'name' => $recipient['name'] ?? null,
                        'role' => $role,
                    ]);
                }
            }

            if ($linkToType !== null && $linkToId !== null) {
                DB::table('emailables')->insert([
                    'email_id' => $email->getKey(),
                    'emailable_type' => $linkToType,
                    'emailable_id' => $linkToId,
                    'link_source' => 'manual',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $email;
        });
    }

    private function assertUnderMaxQueued(string $userId): void
    {
        $maxQueued = Config::integer('email-integration.outbox.max_queued_per_user');

        $queued = Email::query()
            ->where('user_id', $userId)
            ->where('status', EmailStatus::QUEUED)
            ->count();

        throw_if($queued >= $maxQueued, RuntimeException::class, "You have {$queued} emails queued. Clear the outbox before queuing more.");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveScheduledFor(array $data, EmailPriority $priority): ?Carbon
    {
        if (isset($data['scheduled_for']) && $data['scheduled_for'] instanceof DateTimeInterface) {
            return Date::instance($data['scheduled_for']);
        }

        if ($priority === EmailPriority::PRIORITY) {
            $undoWindow = Config::integer('email-integration.outbox.undo_send_window_seconds');

            return now()->addSeconds($undoWindow);
        }

        return null;
    }
}
