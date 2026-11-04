<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Enums\CrmEntity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class ConversationsQuery
{
    public function __construct(
        private PrivacyService $privacy,
        private PreferredEmailCopyService $preferredCopies,
        private EmailSearchService $search,
    ) {}

    /** @return CursorPaginator<int, ConversationRow> */
    public function paginate(User $viewer, ConversationView $view, ConversationFilters $filters, int $perPage = 50, ?string $cursor = null): CursorPaginator
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        $page = $this->conversations($viewer, $view, $filters)
            ->latest('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        return $page->setCollection($this->rows($viewer, $page->getCollection()));
    }

    /** @return EloquentCollection<int, Email> */
    public function messages(User $viewer, string $conversationKey): EloquentCollection
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        $query = $this->delivered(Email::query())
            ->where('emails.workspace_id', $viewer->current_workspace_id)
            ->where('emails.conversation_key', $conversationKey);

        return $this->preferredCopies->restrictToVisiblePreferredCopies($query, $viewer)
            ->withRecordLinkState()
            ->with(['participants', 'shares', 'user'])
            ->oldest('sent_at')
            ->oldest('id')
            ->get();
    }

    /** @return Builder<EmailConversation> */
    private function conversations(User $viewer, ConversationView $view, ConversationFilters $filters): Builder
    {
        return EmailConversation::query()
            ->where('workspace_id', $viewer->current_workspace_id)
            ->whereExists($this->visibleCopiesOf($viewer, $filters)
                ->whereColumn('emails.conversation_key', 'email_conversations.conversation_key')
                ->toBase())
            ->where('is_automated', $view->includesAutomated());
    }

    /** @return Builder<Email> */
    private function visibleCopiesOf(User $viewer, ConversationFilters $filters): Builder
    {
        $query = $this->delivered(Email::query()->withGlobalScope('visible', new VisibleEmailScope($viewer)))
            ->when($filters->mailboxId, fn (Builder $copies, string $mailboxId): Builder => $copies->where('emails.connected_account_id', $mailboxId))
            ->when($filters->direction, fn (Builder $copies, EmailDirection $direction): Builder => $copies->where('emails.direction', $direction))
            ->when($filters->hasAttachment !== null, fn (Builder $copies): Builder => $copies->where('emails.has_attachments', $filters->hasAttachment))
            ->when($filters->sentAfter, fn (Builder $copies, CarbonImmutable $after): Builder => $copies->where('emails.sent_at', '>', $after->utc()))
            ->when($filters->sentBefore, fn (Builder $copies, CarbonImmutable $before): Builder => $copies->where('emails.sent_at', '<', $before->utc()))
            ->when($filters->category, fn (Builder $copies, EmailCategory $category): Builder => $copies->whereHas('labels', fn (Builder $labels): Builder => $labels
                ->where('source', 'system')
                ->where('label', $category)));

        if ($filters->recordType instanceof CrmEntity && $filters->recordId !== null) {
            $query->whereExists(fn (BaseBuilder $link): BaseBuilder => $link
                ->from('emailables')
                ->whereColumn('emailables.email_id', 'emails.id')
                ->where('emailables.emailable_type', $filters->recordType->value)
                ->where('emailables.emailable_id', $filters->recordId));
        }

        if (filled($filters->search)) {
            $this->search->applyToQuery($query, $viewer, $filters->search);
        }

        return $query;
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function delivered(Builder $query): Builder
    {
        return $query
            ->whereIn('emails.status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->whereNotNull('emails.sent_at');
    }

    /**
     * @param  Collection<int, EmailConversation>  $conversations
     * @return Collection<int, ConversationRow>
     */
    private function rows(User $viewer, Collection $conversations): Collection
    {
        $keys = array_values($conversations->map(fn (EmailConversation $conversation): string => $conversation->conversation_key)->all());

        if ($keys === []) {
            return new Collection;
        }

        $copies = $this->newestVisibleCopies($viewer, $keys)->keyBy('conversation_key');
        $stats = $this->visibleMessageStats($viewer, $keys);

        return $conversations
            ->map(function (EmailConversation $conversation) use ($viewer, $copies, $stats): ?ConversationRow {
                $copy = $copies->get($conversation->conversation_key);
                $copyStats = $stats->get($conversation->conversation_key);
                $access = $copy instanceof Email ? $this->privacy->effectiveTier($copy, $viewer) : null;

                if (! $copy instanceof Email || $copyStats === null || ! $access instanceof EmailPrivacyTier) {
                    return null;
                }

                return $this->row($viewer, $copy, $access, $copyStats);
            })
            ->filter()
            ->values();
    }

    /** @param  object{message_count: int|string, last_message_at: string, has_attachments: bool}  $stats */
    private function row(User $viewer, Email $copy, EmailPrivacyTier $access, object $stats): ConversationRow
    {
        $isTeammateCopy = $copy->user_id !== $viewer->getKey();
        $senders = $copy->participants->where('role', EmailParticipantRole::FROM);
        $recipients = $copy->participants->where('role', EmailParticipantRole::TO);

        return new ConversationRow(
            conversationKey: (string) $copy->conversation_key,
            viewerCopyEmailId: $copy->getKey(),
            subject: $access->showsSubject() ? $copy->subject : null,
            snippet: $access->showsBody() ? $copy->snippet : null,
            senderNames: $this->names($senders),
            participants: $this->names($senders->concat($recipients)),
            messageCount: (int) $stats->message_count,
            lastMessageAt: CarbonImmutable::parse($stats->last_message_at, 'UTC'),
            hasAttachments: (bool) $stats->has_attachments,
            isUnread: false,
            ownerUserId: $isTeammateCopy ? $copy->user_id : null,
            ownerName: $isTeammateCopy ? $copy->user?->name : null,
            access: $access,
            viewerHasPendingRequest: (bool) $copy->getAttribute('viewer_has_pending_access_request'),
            records: [],
            automatedReason: $copy->automated_reason,
        );
    }

    /**
     * @param  Collection<int, EmailParticipant>  $participants
     * @return list<string>
     */
    private function names(Collection $participants): array
    {
        return array_values($participants
            ->map(fn (EmailParticipant $participant): string => (string) ($participant->name ?: $participant->email_address))
            ->filter()
            ->unique()
            ->all());
    }

    /**
     * @param  list<string>  $keys
     * @return EloquentCollection<int, Email>
     */
    private function newestVisibleCopies(User $viewer, array $keys): EloquentCollection
    {
        return $this->visibleCopiesOf($viewer, new ConversationFilters)
            ->whereIn('emails.conversation_key', $keys)
            ->selectRaw('distinct on (emails.conversation_key) emails.*')
            ->withRecordLinkState()
            ->selectSub($this->viewerSyncedCopy($viewer), 'viewer_has_synced_copy')
            ->withExists(['accessRequests as viewer_has_pending_access_request' => fn (Builder $requests): Builder => $requests
                ->where('requester_id', $viewer->getKey())
                ->scopes(['open'])])
            ->with(['participants', 'shares', 'user'])
            ->orderBy('emails.conversation_key')
            ->orderByDesc('emails.sent_at')
            ->orderByRaw('case when emails.user_id = ? then 0 else 1 end', [$viewer->getKey()])
            ->orderByRaw('array_position(?::text[], emails.privacy_tier::text) desc nulls last', [EmailPrivacyTier::opennessRanking()])
            ->orderByDesc('emails.id')
            ->get();
    }

    private function viewerSyncedCopy(User $viewer): BaseBuilder
    {
        return DB::table('emails as viewer_copies')
            ->join('connected_accounts as viewer_copy_accounts', 'viewer_copy_accounts.id', '=', 'viewer_copies.connected_account_id')
            ->whereNull('viewer_copies.deleted_at')
            ->whereNull('viewer_copy_accounts.deleted_at')
            ->whereColumn('viewer_copies.workspace_id', 'emails.workspace_id')
            ->whereColumn('viewer_copies.rfc_message_id', 'emails.rfc_message_id')
            ->where('viewer_copies.user_id', $viewer->getKey())
            ->selectRaw('true')
            ->limit(1);
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<string, object{message_count: int|string, last_message_at: string, has_attachments: bool}>
     */
    private function visibleMessageStats(User $viewer, array $keys): Collection
    {
        /** @var Collection<string, object{message_count: int|string, last_message_at: string, has_attachments: bool}> */
        return $this->visibleCopiesOf($viewer, new ConversationFilters)
            ->whereIn('emails.conversation_key', $keys)
            ->toBase()
            ->select('emails.conversation_key')
            ->selectRaw('count(distinct coalesce(emails.rfc_message_id, emails.id::text)) as message_count')
            ->selectRaw('max(emails.sent_at) as last_message_at')
            ->selectRaw('bool_or(emails.has_attachments) as has_attachments')
            ->groupBy('emails.conversation_key')
            ->get()
            ->keyBy('conversation_key');
    }
}
