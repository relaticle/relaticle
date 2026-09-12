<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\InboundEmailData;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\ForwardingBlocklistMatcher;
use Relaticle\EmailIntegration\Services\ForwardingPrivacyService;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

final readonly class StoreInboundEmailAction
{
    public function __construct(
        private ForwardingBlocklistMatcher $blocklist,
        private ForwardingPrivacyService $privacy,
        private SeedForwardingFullAccessSharesAction $seedShares,
    ) {}

    public function execute(User $user, Team $team, InboundEmailData $data): ?Email
    {
        $participantAddresses = array_map(
            fn (array $participant): string => strtolower($participant['email_address']),
            $data->participants,
        );

        if ($this->blocklist->isBlocked($user->getKey(), $team->getKey(), $participantAddresses)) {
            return null;
        }

        if ($data->rfcMessageId !== '' && Email::query()
            ->withoutGlobalScopes()
            ->where('team_id', $team->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('connected_account_id')
            ->where('rfc_message_id', $data->rfcMessageId)
            ->exists()) {
            return null;
        }

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($user, $team, $data, $participantAddresses, &$storedPaths): Email {
                $email = Email::query()->create([
                    'team_id' => $team->getKey(),
                    'user_id' => $user->getKey(),
                    'connected_account_id' => null,
                    'rfc_message_id' => $data->rfcMessageId,
                    'provider_message_id' => null,
                    'thread_id' => null,
                    'in_reply_to' => $data->inReplyTo,
                    'subject' => $data->subject,
                    'snippet' => $data->snippet,
                    'sent_at' => $data->sentAt,
                    'direction' => EmailDirection::INBOUND,
                    'folder' => EmailFolder::Inbox,
                    'status' => EmailStatus::SYNCED,
                    'privacy_tier' => $this->privacy->defaultSharingTier($user, $team),
                    'has_attachments' => $data->attachments !== [],
                    'creation_source' => EmailCreationSource::BCC_INBOUND,
                ]);

                $email->body()->create([
                    'body_text' => $data->bodyText,
                    'body_html' => $data->bodyHtml,
                ]);

                foreach ($data->participants as $participant) {
                    EmailParticipant::query()->create([
                        'email_id' => $email->getKey(),
                        'email_address' => strtolower($participant['email_address']),
                        'name' => $participant['name'],
                        'role' => $participant['role'],
                    ]);
                }

                foreach ($data->attachments as $attachment) {
                    $path = $this->storeAttachment($email, $attachment, $storedPaths);

                    EmailAttachment::query()->create([
                        'email_id' => $email->getKey(),
                        'filename' => $this->filename($attachment),
                        'mime_type' => $attachment['mime_type'],
                        'size' => $attachment['size'],
                        'content_id' => $attachment['content_id'],
                        'is_inline' => filled($attachment['content_id']),
                        'provider_attachment_id' => null,
                        'storage_path' => $path,
                    ]);
                }

                $teamUserEmails = $team->allUsers()
                    ->pluck('email')
                    ->map(fn (string $address): string => strtolower($address));

                $isInternal = $participantAddresses !== []
                    && collect($participantAddresses)->every(
                        fn (string $address): bool => $teamUserEmails->contains($address),
                    );

                $email->updateQuietly(['is_internal' => $isInternal]);

                EmailLabel::query()->create([
                    'email_id' => $email->getKey(),
                    'label' => ($isInternal ? EmailCategory::Personal : EmailCategory::Other)->value,
                    'source' => 'system',
                    'created_at' => now(),
                ]);

                $this->seedShares->execute($email, $user, $team);
                resolve(LinkEmailAction::class)->execute($email);

                return $email;
            });
        } catch (Throwable $exception) {
            Storage::disk(EmailAttachment::DISK)->delete($storedPaths);

            throw $exception;
        }
    }

    /**
     * @param  array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, content: string}  $attachment
     * @param  list<string>  $storedPaths
     */
    private function storeAttachment(Email $email, array $attachment, array &$storedPaths): ?string
    {
        if ($attachment['content'] === '') {
            return null;
        }

        $binary = base64_decode($attachment['content'], true);

        if ($binary === false) {
            return null;
        }

        $path = "email-attachments/{$email->team_id}/".Str::ulid();

        Storage::disk(EmailAttachment::DISK)->put($path, $binary);
        $storedPaths[] = $path;

        return $path;
    }

    /**
     * @param  array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, content: string}  $attachment
     */
    private function filename(array $attachment): string
    {
        if (filled($attachment['filename'])) {
            return (string) $attachment['filename'];
        }

        $extensions = MimeTypes::getDefault()->getExtensions((string) ($attachment['mime_type'] ?? 'application/octet-stream'));

        return 'attachment.'.($extensions[0] ?? 'bin');
    }
}
