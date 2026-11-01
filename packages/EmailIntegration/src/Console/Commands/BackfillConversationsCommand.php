<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\RefreshConversationSummary;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Jobs\MergeConversations;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Services\ConversationKeyResolver;
use Relaticle\EmailIntegration\Support\MessageIdList;
use stdClass;

#[Description('Give stored emails a conversation key and rebuild conversation summaries')]
#[Signature('email:backfill-conversations
    {--force : Write keys and summaries instead of reporting them}
    {--workspace= : Only this workspace}
    {--chunk=10000 : Emails to key and summaries to rebuild in one run before queueing the rest}')]
final class BackfillConversationsCommand extends Command
{
    public function handle(ConversationKeyResolver $resolver, RefreshConversationSummary $refresh, CurrentWorkspace $currentWorkspace): int
    {
        if (! $this->option('force')) {
            $this->comment("{$this->unkeyed()->count()} email(s) without a conversation key. Re-run with --force to write them.");

            return self::SUCCESS;
        }

        // Stale statistics read a mostly null key as rare, and every key lookup then walks all keyed mail.
        DB::statement('analyze emails (conversation_key)');

        $budget = max(1, (int) $this->option('chunk'));
        $keyed = $this->keyUnkeyed($resolver, $currentWorkspace, $budget);

        if ($keyed === $budget && $this->unkeyed()->exists()) {
            return $this->queueRest("{$keyed} email(s) keyed.");
        }

        $stale = $this->staleSummaries()->limit($budget - $keyed + 1)->get();
        $rebuilt = $this->rebuild($stale->take($budget - $keyed), $refresh, $currentWorkspace);

        if ($stale->count() > $rebuilt) {
            return $this->queueRest("{$keyed} email(s) keyed, {$rebuilt} summary(ies) rebuilt.");
        }

        $this->comment("{$keyed} email(s) keyed, {$rebuilt} summary(ies) rebuilt.");

        return self::SUCCESS;
    }

    private function keyUnkeyed(ConversationKeyResolver $resolver, CurrentWorkspace $currentWorkspace, int $budget): int
    {
        $keyed = 0;
        $workspaces = Workspace::query()->whereIn('id', $this->unkeyed()->select('workspace_id'))->lazyById();

        foreach ($workspaces as $workspace) {
            $keyed += $currentWorkspace->within($workspace, function () use ($workspace, $resolver, $budget, $keyed): int {
                $count = 0;

                foreach ($this->unkeyed()->where('workspace_id', $workspace->getKey())->lazyById(500) as $email) {
                    if ($keyed + $count === $budget) {
                        break;
                    }

                    $this->key($email, $resolver);
                    $count++;
                }

                return $count;
            });

            if ($keyed === $budget) {
                break;
            }
        }

        return $keyed;
    }

    private function key(Email $email, ConversationKeyResolver $resolver): void
    {
        // A failed merge rolls the key back, so the next run keys and merges this email again.
        DB::transaction(function () use ($email, $resolver): void {
            $email->in_reply_to = MessageIdList::first($email->in_reply_to);
            $email->conversation_key = $resolver->resolveStored($email);

            $written = Email::query()->withoutGlobalScopes()
                ->whereKey($email->getKey())
                ->whereNull('conversation_key')
                ->toBase()
                ->update(['conversation_key' => $email->conversation_key, 'in_reply_to' => $email->in_reply_to]);

            if ($written === 1 && $resolver->keysToMerge($email) !== []) {
                dispatch_sync(new MergeConversations($email->workspace_id, $email->getKey()));
            }
        });
    }

    /** @param  Collection<int, stdClass>  $stale */
    private function rebuild(Collection $stale, RefreshConversationSummary $refresh, CurrentWorkspace $currentWorkspace): int
    {
        $stale->groupBy('workspace_id')->each(function (Collection $conversations, string $workspaceId) use ($refresh, $currentWorkspace): void {
            $currentWorkspace->within($workspaceId, function () use ($conversations, $workspaceId, $refresh): void {
                foreach ($conversations as $conversation) {
                    $refresh->execute($workspaceId, $conversation->conversation_key);
                }
            });
        });

        return $stale->count();
    }

    /** @return Builder<Email> */
    private function unkeyed(): Builder
    {
        return Email::query()->withoutGlobalScopes()
            ->whereNull('conversation_key')
            ->where('status', '!=', EmailStatus::DRAFT)
            ->when($this->option('workspace'), fn (Builder $query, string $id): Builder => $query->where('workspace_id', $id));
    }

    private function staleSummaries(): QueryBuilder
    {
        // Default scopes, so mail of a disconnected mailbox drops out of its summary.
        $live = Email::query()->toBase()
            ->whereNotNull('conversation_key')
            ->whereIn('status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->whereNotNull('sent_at')
            ->when($this->option('workspace'), fn (QueryBuilder $query, string $id): QueryBuilder => $query->where('workspace_id', $id))
            ->groupBy('workspace_id', 'conversation_key')
            ->select('workspace_id', 'conversation_key')
            ->selectRaw('count(distinct coalesce(rfc_message_id, id::text)) as message_count')
            ->selectRaw('min(sent_at) as first_message_at, max(sent_at) as last_message_at')
            ->selectRaw('coalesce(bool_or(has_attachments), false) as has_attachments');

        $stale = DB::query()->fromSub($live, 'live')
            ->leftJoin('email_conversations as summary', fn (JoinClause $join): JoinClause => $join
                ->on('summary.workspace_id', '=', 'live.workspace_id')
                ->on('summary.conversation_key', '=', 'live.conversation_key'))
            ->where(fn (QueryBuilder $query): QueryBuilder => $query
                ->whereNull('summary.id')
                ->orWhereColumn('summary.message_count', '!=', 'live.message_count')
                ->orWhereColumn('summary.first_message_at', '!=', 'live.first_message_at')
                ->orWhereColumn('summary.last_message_at', '!=', 'live.last_message_at')
                ->orWhereColumn('summary.has_attachments', '!=', 'live.has_attachments'))
            ->select('live.workspace_id', 'live.conversation_key');

        $orphans = EmailConversation::query()->toBase()
            ->when($this->option('workspace'), fn (QueryBuilder $query, string $id): QueryBuilder => $query->where('workspace_id', $id))
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->fromSub($live, 'live')
                ->whereColumn('live.workspace_id', 'email_conversations.workspace_id')
                ->whereColumn('live.conversation_key', 'email_conversations.conversation_key'))
            ->select('workspace_id', 'conversation_key');

        return $stale->union($orphans);
    }

    private function queueRest(string $progress): int
    {
        Artisan::queue('email:backfill-conversations', array_filter([
            '--force' => true,
            '--workspace' => $this->option('workspace'),
            '--chunk' => $this->option('chunk'),
        ]))->onQueue('imports')->delay(now()->addMinute());

        $this->comment("{$progress} The rest is queued.");

        return self::SUCCESS;
    }
}
