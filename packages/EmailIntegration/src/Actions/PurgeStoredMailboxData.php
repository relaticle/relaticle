<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Events\ConversationChanged;
use Relaticle\EmailIntegration\Jobs\RefreshConversationSummaries;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class PurgeStoredMailboxData
{
    public function __construct(private ConversationsQuery $conversations) {}

    private const int FILES_PER_DELETE = 500;

    private const int CONVERSATIONS_PER_JOB = 200;

    // Mail rows leave through foreign-key cascades, which fire no model event,
    // so the files and summaries are gathered here before the owner is deleted.
    public function execute(User|Workspace $owner): void
    {
        $paths = EmailAttachment::query()
            ->whereNotNull('storage_path')
            ->whereIn('email_id', $this->emails($owner)->select('id'))
            ->pluck('storage_path')
            ->all();

        $conversations = [];

        if ($owner instanceof User) {
            $conversations = $this->conversations($owner);
            $this->deleteThreadSummaries($owner);
        }

        DB::afterCommit(function () use ($owner, $paths, $conversations): void {
            $disk = Storage::disk(EmailAttachment::DISK);

            foreach (array_chunk($paths, self::FILES_PER_DELETE) as $chunk) {
                $disk->delete($chunk);
            }

            if ($owner instanceof Workspace) {
                $disk->deleteDirectory(EmailAttachment::composeImagesDirectory((string) $owner->getKey()));
            }

            foreach ($conversations as $workspaceId => $chunks) {
                foreach ($chunks as [$chunk, $audiences]) {
                    dispatch(new RefreshConversationSummaries((string) $workspaceId, $chunk, $audiences));
                }
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

    // Read before the rows go, so the members who lose a conversation still hear that it changed.
    /** @return array<string, list<array{0: list<string>, 1: array<string, list<string>>}>> key chunks and their audiences by workspace */
    private function conversations(User $user): array
    {
        $keys = [];

        /** @var object{workspace_id: string, conversation_key: string} $conversation */
        foreach ($this->emails($user)->whereNotNull('conversation_key')->toBase()->distinct()->get(['workspace_id', 'conversation_key']) as $conversation) {
            $keys[$conversation->workspace_id][] = $conversation->conversation_key;
        }

        $chunks = [];

        foreach ($keys as $workspaceId => $workspaceKeys) {
            foreach (array_chunk($workspaceKeys, self::CONVERSATIONS_PER_JOB) as $chunk) {
                $chunks[$workspaceId][] = [$chunk, ConversationChanged::broadcasts() ? $this->conversations->audiences((string) $workspaceId, $chunk) : []];
            }
        }

        return $chunks;
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

        AiSummary::query()->where('user_id', $user->getKey())->delete();
        AiSummary::query()->where('summarizable_type', (new EmailConversation)->getMorphClass())
            ->whereIn('summarizable_id', EmailConversation::query()->whereExists(
                fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->selectRaw('1')->fromSub($this->emails($user), 'owned_emails')
                    ->whereColumn('owned_emails.workspace_id', 'email_conversations.workspace_id')
                    ->whereColumn('owned_emails.conversation_key', 'email_conversations.conversation_key')
            )->select('id'))->delete();
    }

    /** @return Builder<ConnectedAccount> */
    private function mailboxes(User $user): Builder
    {
        return ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getKey());
    }
}
