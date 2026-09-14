<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Throwable;

final readonly class ForwardAttachmentCopyService
{
    /**
     * Per-file cap, matching the Filament compose modal and docked composer.
     */
    public const int MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /**
     * Whole-message cap before base64 overhead pushes the outbound MIME past provider limits.
     */
    public const int MAX_ATTACHMENTS_TOTAL_BYTES = 15 * 1024 * 1024;

    /**
     * Non-inline files from the source email that fit the forward size limits.
     *
     * @return array{0: EloquentCollection<int, EmailAttachment>, 1: list<string>}
     */
    public function forwardableNonInlineAttachments(Email $source): array
    {
        $source->loadMissing('attachments');

        $kept = new EloquentCollection;
        $rejected = [];
        $total = 0;

        foreach ($source->downloadAttachments() as $attachment) {
            $size = (int) $attachment->size;

            if ($size > self::MAX_ATTACHMENT_BYTES || $total + $size > self::MAX_ATTACHMENTS_TOTAL_BYTES) {
                $rejected[] = (string) $attachment->filename;

                continue;
            }

            $total += $size;
            $kept->push($attachment);
        }

        return [$kept, $rejected];
    }

    /**
     * Copy every forwardable file from the source for modal reply/forward (no attachment UI).
     *
     * @return array{
     *     paths: list<string>,
     *     names: array<string, string>,
     *     attributes: array<string, array{is_inline: bool, content_id: ?string}>,
     *     unavailable: list<EmailAttachment>,
     *     rejected_filenames: list<string>,
     * }
     */
    public function copyAllForForward(User $user, Email $source): array
    {
        [$nonInline, $rejected] = $this->forwardableNonInlineAttachments($source);

        [$paths, $names, $attributes, $unavailable] = $this->copyRecords($user, $nonInline);
        [$inlinePaths, $inlineNames, $inlineAttributes, $inlineUnavailable] = $this->copyInlineFromSource($user, $source);

        return [
            'paths' => [...$paths, ...$inlinePaths],
            'names' => [...$names, ...$inlineNames],
            'attributes' => [...$attributes, ...$inlineAttributes],
            'unavailable' => [...$unavailable, ...$inlineUnavailable],
            'rejected_filenames' => $rejected,
        ];
    }

    /**
     * @param  list<string>  $attachmentIds
     * @return array{0: list<string>, 1: array<string, string>, 2: array<string, array{is_inline: bool, content_id: ?string}>, 3: list<EmailAttachment>}
     */
    public function copyNonInlineByIds(User $user, Email $source, array $attachmentIds): array
    {
        if ($attachmentIds === []) {
            return [[], [], [], []];
        }

        $attachments = EmailAttachment::query()
            ->with('email.connectedAccount')
            ->where('email_id', $source->getKey())
            ->where('is_inline', false)
            ->whereIn('id', $attachmentIds)
            ->get();

        return $this->copyRecords($user, $attachments);
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>, 2: array<string, array{is_inline: bool, content_id: ?string}>, 3: list<EmailAttachment>}
     */
    public function copyInlineFromSource(User $user, Email $source): array
    {
        $attachments = EmailAttachment::query()
            ->with('email.connectedAccount')
            ->where('email_id', $source->getKey())
            ->where('is_inline', true)
            ->get();

        return $this->copyRecords($user, $attachments, inline: true);
    }

    /**
     * @param  iterable<int, EmailAttachment>  $attachments
     * @return array{0: list<string>, 1: array<string, string>, 2: array<string, array{is_inline: bool, content_id: ?string}>, 3: list<EmailAttachment>}
     */
    public function copyRecords(User $user, iterable $attachments, bool $inline = false): array
    {
        $paths = [];
        $names = [];
        $attributes = [];
        $unavailable = [];

        foreach ($attachments as $attachment) {
            $copy = $this->copyAttachmentFile($user, $attachment);

            if ($copy === null) {
                $unavailable[] = $attachment;

                continue;
            }

            $paths[] = $copy;
            $names[$copy] = (string) $attachment->filename;

            if ($inline || $attachment->is_inline) {
                $attributes[$copy] = [
                    'is_inline' => true,
                    'content_id' => $attachment->content_id,
                ];
            }
        }

        return [$paths, $names, $attributes, $unavailable];
    }

    /**
     * @param  list<string>  $paths
     */
    public function deleteCopiedFiles(array $paths): void
    {
        $disk = Storage::disk(EmailAttachment::DISK);

        foreach ($paths as $path) {
            $disk->delete($path);
        }
    }

    private function copyAttachmentFile(User $user, EmailAttachment $attachment): ?string
    {
        $disk = Storage::disk(EmailAttachment::DISK);
        $extension = pathinfo((string) $attachment->filename, PATHINFO_EXTENSION);

        if ($extension === '' && is_string($attachment->storage_path)) {
            $extension = pathinfo($attachment->storage_path, PATHINFO_EXTENSION);
        }

        $copy = 'email-attachments/'.Str::ulid().($extension !== '' ? '.'.$extension : '');
        $source = $attachment->storage_path;

        if (is_string($source) && $source !== '' && $disk->exists($source)) {
            $disk->copy($source, $copy);

            return $copy;
        }

        $bytes = $this->downloadProviderAttachment($user, $attachment);

        if ($bytes === null) {
            return null;
        }

        $disk->put($copy, $bytes);

        return $copy;
    }

    private function downloadProviderAttachment(User $user, EmailAttachment $attachment): ?string
    {
        $email = $attachment->email;
        $providerAttachmentId = $attachment->provider_attachment_id;

        if (! $email instanceof Email || blank($providerAttachmentId)) {
            return null;
        }

        $source = $this->providerDownloadSource($user, $email);

        if (! $source instanceof Email || blank($source->provider_message_id)) {
            return null;
        }

        $account = $source->connectedAccount;

        if (! $account instanceof ConnectedAccount) {
            return null;
        }

        try {
            return resolve(MailServiceFactoryInterface::class)
                ->make($account)
                ->downloadAttachment($source->provider_message_id, $providerAttachmentId);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function providerDownloadSource(User $user, Email $email): ?Email
    {
        if (filled($email->provider_message_id)) {
            return $email;
        }

        if ($email->status !== EmailStatus::DRAFT || blank($email->in_reply_to)) {
            return null;
        }

        $source = Email::query()
            ->with('connectedAccount')
            ->where('workspace_id', $user->current_workspace_id)
            ->where('rfc_message_id', $email->in_reply_to)
            ->withGlobalScope('visible', new VisibleEmailScope($user))
            ->first();

        return $source instanceof Email && $user->can('viewBody', $source) ? $source : null;
    }
}
