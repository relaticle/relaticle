<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailThread;

final readonly class PurgeStoredMailboxData
{
    private const int FILES_PER_DELETE = 500;

    // Mail rows leave through foreign-key cascades, which fire no model event,
    // so the files and summaries are gathered here before the owner is deleted.
    public function execute(User|Workspace $owner): void
    {
        $paths = EmailAttachment::query()
            ->whereNotNull('storage_path')
            ->whereIn('email_id', $this->emails($owner)->select('id'))
            ->pluck('storage_path')
            ->all();

        if ($owner instanceof User) {
            $this->deleteThreadSummaries($owner);
        }

        DB::afterCommit(function () use ($owner, $paths): void {
            $disk = Storage::disk(EmailAttachment::DISK);

            foreach (array_chunk($paths, self::FILES_PER_DELETE) as $chunk) {
                $disk->delete($chunk);
            }

            if ($owner instanceof Workspace) {
                $disk->deleteDirectory(EmailAttachment::composeImagesDirectory((string) $owner->getKey()));
            }
        });
    }

    /** @return Builder<Email> */
    private function emails(User|Workspace $owner): Builder
    {
        $emails = Email::query()->withoutGlobalScopes();

        if ($owner instanceof Workspace) {
            return $emails->where('workspace_id', $owner->getKey());
        }

        return $emails->where(fn (Builder $query) => $query
            ->where('user_id', $owner->getKey())
            ->orWhereIn('connected_account_id', $this->mailboxes($owner)->select('id')));
    }

    private function deleteThreadSummaries(User $user): void
    {
        $threads = EmailThread::query()
            ->withoutGlobalScopes()
            ->whereIn('connected_account_id', $this->mailboxes($user)->select('id'));

        AiSummary::query()
            ->where('summarizable_type', (new EmailThread)->getMorphClass())
            ->whereIn('summarizable_id', $threads->select('id'))
            ->delete();
    }

    /** @return Builder<ConnectedAccount> */
    private function mailboxes(User $user): Builder
    {
        return ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getKey());
    }
}
