<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Number;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Services\ForwardAttachmentCopyService;

trait PreparesForwardAttachmentSendData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null Updated send form data, or null when send must abort.
     */
    protected function mergeForwardAttachmentsIntoSendData(User $user, ?Email $forwardSource, array $data): ?array
    {
        if (! $forwardSource instanceof Email || $user->cannot('viewBody', $forwardSource)) {
            return $data;
        }

        $copier = resolve(ForwardAttachmentCopyService::class);
        $copied = $copier->copyAllForForward($user, $forwardSource);

        if ($copied['unavailable'] !== []) {
            $this->notifyUnavailableForwardedAttachments($copied['unavailable'], abortingSend: true);
            $copier->deleteCopiedFiles($copied['paths']);

            return null;
        }

        if ($copied['rejected_filenames'] !== []) {
            Notification::make()
                ->warning()
                ->title(__('filament/emails/composer.notifications.attachment_too_large.title'))
                ->body(__('filament/emails/composer.notifications.attachment_too_large.body', [
                    'files' => implode(', ', $copied['rejected_filenames']),
                    'max' => Number::fileSize(ForwardAttachmentCopyService::MAX_ATTACHMENT_BYTES),
                    'total' => Number::fileSize(ForwardAttachmentCopyService::MAX_ATTACHMENTS_TOTAL_BYTES),
                ]))
                ->send();
        }

        $data['attachments'] = $copied['paths'];
        $data['attachment_file_names'] = $copied['names'];
        $data['attachment_attributes'] = $copied['attributes'];

        return $data;
    }

    /**
     * @param  list<EmailAttachment>  $attachments
     */
    private function notifyUnavailableForwardedAttachments(array $attachments, bool $abortingSend): void
    {
        $key = $abortingSend
            ? 'filament/emails/composer.notifications.send_attachment_unavailable'
            : 'filament/emails/composer.notifications.attachment_unavailable';

        $notification = Notification::make()
            ->title(__($key.'.title'))
            ->body(__($key.'.body', [
                'files' => implode(', ', array_map(
                    fn (EmailAttachment $attachment): string => (string) $attachment->filename,
                    $attachments,
                )),
            ]));

        $abortingSend ? $notification->danger() : $notification->warning();

        $notification->send();
    }
}
