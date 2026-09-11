<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;

/**
 * Detach a single already-saved attachment from a draft, bytes included.
 * Only ever applies to DRAFT rows owned by the user: a sent or queued email's
 * attachments are a record of what was delivered and must stay put.
 */
final readonly class DeleteDraftAttachmentAction
{
    public function execute(User $user, string $draftId, string $attachmentId): void
    {
        $attachment = EmailAttachment::query()
            ->whereKey($attachmentId)
            ->where('email_id', $draftId)
            ->whereHas('email', fn (Builder $query): Builder => $query
                ->where('user_id', $user->getKey())
                ->where('team_id', $user->current_team_id)
                ->where('status', EmailStatus::DRAFT))
            ->first();

        abort_if(! $attachment instanceof EmailAttachment, 403);

        DB::transaction(function () use ($attachment, $draftId): void {
            if ($attachment->storage_path !== null) {
                Storage::disk(EmailAttachment::DISK)->delete($attachment->storage_path);
            }

            $attachment->delete();

            $draft = Email::query()->whereKey($draftId)->first();

            $draft?->update(['has_attachments' => $draft->attachments()->exists()]);
        });
    }
}
