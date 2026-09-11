<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\EmailInlineImageEmbedder;
use RuntimeException;

final readonly class SendEmailAction
{
    public function __construct(
        private EmailInlineImageEmbedder $inlineImageEmbedder,
    ) {}

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
     *     attachments?: array<int, string>,
     *     attachment_file_names?: array<string, string>,
     *     attachment_attributes?: array<string, array{is_inline?: bool, content_id?: ?string}>,
     * }  $data
     * @param  class-string|null  $linkToType
     */
    public function execute(array $data, ?string $linkToType = null, ?string $linkToId = null): Email
    {
        /** @var User $user */
        $user = auth()->user();

        /** @var ConnectedAccount $account */
        $account = ConnectedAccount::query()
            ->ownedBy($user, $user->currentTeam)
            ->whereKey($data['connected_account_id'])
            ->firstOrFail();

        abort_unless($account->isSendable(), 403);

        $priority = $data['priority'] ?? EmailPriority::BULK;

        $this->assertUnderMaxQueued((string) $account->user_id);

        $scheduledFor = $this->resolveScheduledFor($data, $priority);

        $embedded = $this->inlineImageEmbedder->embed(
            (string) $data['body_html'],
            array_values($data['attachments'] ?? []),
            $data['attachment_file_names'] ?? [],
            $data['attachment_attributes'] ?? [],
        );

        $data['body_html'] = $embedded['body_html'];

        /** @var array<int, string> $attachmentPaths */
        $attachmentPaths = $embedded['attachments'];

        /** @var array<string, array{is_inline?: bool, content_id?: ?string}> $attachmentAttributes */
        $attachmentAttributes = $embedded['attachment_attributes'];

        /** @var array<string, string> $attachmentFileNames */
        $attachmentFileNames = $embedded['attachment_file_names'];

        $hasDownloadableAttachments = collect($attachmentPaths)
            ->contains(fn (string $path): bool => ($attachmentAttributes[$path]['is_inline'] ?? false) !== true);

        return DB::transaction(function () use ($account, $data, $priority, $scheduledFor, $linkToType, $linkToId, $attachmentPaths, $attachmentAttributes, $attachmentFileNames, $hasDownloadableAttachments): Email {
            // Scope the reply lookup to the sender's team. in_reply_to_email_id arrives
            // from a client-controlled hidden field and Email has no team global scope,
            // so an unscoped lookup would let a user thread their outbound mail onto
            // another tenant's email and leak its thread_id / rfc_message_id.
            /** @var Email|null $inReplyTo */
            $inReplyTo = isset($data['in_reply_to_email_id'])
                ? Email::query()
                    ->where('team_id', $account->team_id)
                    ->whereKey($data['in_reply_to_email_id'])
                    ->first()
                : null;

            /** @var Email $email */
            $email = Email::query()->create([
                'team_id' => $account->team_id,
                'user_id' => $account->user_id,
                'connected_account_id' => $account->getKey(),
                // Stamp a stable RFC Message-ID now (used as the outgoing Message-ID
                // header) so a retry that re-enters after the provider already
                // delivered can find the sent message and adopt it instead of
                // double-sending. See EmailSendingService::send().
                'rfc_message_id' => $this->generateRfcMessageId($account),
                'provider_message_id' => null,
                // Gmail threadId / Graph conversationId exist only inside the
                // mailbox that received the original. A reply from a shared
                // inbox or a different connected account must not pass that
                // foreign id to the sending mailbox.
                'thread_id' => $inReplyTo !== null && $inReplyTo->connected_account_id === $account->getKey()
                    ? $inReplyTo->thread_id
                    : null,
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
                'has_attachments' => $hasDownloadableAttachments,
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

            $this->storeAttachments($email, $attachmentPaths, $attachmentFileNames, $attachmentAttributes);

            if ($linkToType !== null && $linkToId !== null && in_array($linkToType, [Company::class, Opportunity::class, People::class], true)) {
                $linked = $linkToType::query()->whereKey($linkToId)->first();

                if ($linked instanceof Company || $linked instanceof Opportunity || $linked instanceof People) {
                    // Attach through the record relation so emailable_type is the
                    // morph alias (`people`), not the class name. Relation::enforceMorphMap
                    // means `$person->emails()` would miss a fully-qualified class name.
                    $linked->emails()->attach($email->getKey(), ['link_source' => 'manual']);
                }
            }

            return $email;
        });
    }

    /**
     * Persist compose-uploaded attachments so the send job can attach their bytes.
     * Paths point at temporary files on the compose disk written by the FileUpload.
     *
     * @param  array<int, string>  $paths
     * @param  array<string, string>  $originalNames  storage path => original client filename
     * @param  array<string, array{is_inline?: bool, content_id?: ?string}>  $attributes
     */
    private function storeAttachments(Email $email, array $paths, array $originalNames, array $attributes): void
    {
        $disk = Storage::disk(EmailAttachment::DISK);

        foreach ($paths as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            EmailAttachment::query()->create([
                'email_id' => $email->getKey(),
                'filename' => $originalNames[$path] ?? basename($path),
                'mime_type' => $disk->mimeType($path) ?: 'application/octet-stream',
                'size' => $disk->size($path),
                'storage_path' => $path,
                'is_inline' => $attributes[$path]['is_inline'] ?? false,
                'content_id' => $attributes[$path]['content_id'] ?? null,
            ]);
        }
    }

    /**
     * Build a globally-unique RFC 2822 Message-ID rooted at the sender's domain.
     */
    private function generateRfcMessageId(ConnectedAccount $account): string
    {
        $email = (string) $account->email_address;
        $domain = str_contains($email, '@') ? Str::after($email, '@') : 'relaticle.app';

        return '<'.Str::ulid().'@'.$domain.'>';
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
    private function resolveScheduledFor(array $data, EmailPriority $priority): ?CarbonInterface
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
