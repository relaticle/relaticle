<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationPage;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
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

    public function paginate(User $viewer, ConversationView $view, ConversationFilters $filters, int $perPage = 50, ?string $cursor = null): ConversationPage
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        $visible = new VisibleEmailScope($viewer);
        $page = $this->conversations($viewer, $visible, $view, $filters)
            ->latest('last_message_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        // Cursors are read off the summary models, which carry the order columns the rows do not.
        return new ConversationPage(
            rows: array_values($this->rows($viewer, $visible, $page->getCollection())->all()),
            nextCursor: $page->nextCursor()?->encode(),
            previousCursor: $page->previousCursor()?->encode(),
        );
    }

    /** @return array{needs_reply: int, unlinked: int, automated: int} */
    public function counts(User $viewer, ?ConversationFilters $filters = null): array
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        $filters ??= new ConversationFilters;
        $visible = new VisibleEmailScope($viewer);
        $counts = DB::query();

        foreach ([ConversationView::NeedsReply, ConversationView::Unlinked, ConversationView::Automated] as $view) {
            $counts->selectSub($this->cappedCount($viewer, $visible, $view, $filters), $view->value);
        }

        $row = (array) $counts->first();

        return [
            'needs_reply' => (int) $row[ConversationView::NeedsReply->value],
            'unlinked' => (int) $row[ConversationView::Unlinked->value],
            'automated' => (int) $row[ConversationView::Automated->value],
        ];
    }

    public function count(User $viewer, ConversationView $view, ConversationFilters $filters): int
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        return (int) $this->cappedCount($viewer, new VisibleEmailScope($viewer), $view, $filters)->value('count');
    }

    public function contains(User $viewer, string $conversationKey, ConversationFilters $filters): bool
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        return $this->visibleCopiesOf($viewer, new VisibleEmailScope($viewer), $filters)
            ->where('emails.conversation_key', $conversationKey)
            ->exists();
    }

    /**
     * @param  VisibleEmailScope<Model>  $visible
     */
    private function cappedCount(User $viewer, VisibleEmailScope $visible, ConversationView $view, ConversationFilters $filters): BaseBuilder
    {
        // The view order keeps the planner on the view indexes; unordered, Unlinked hashes every email first.
        $matches = $this->conversations($viewer, $visible, $view, $filters)
            ->latest('last_message_at')
            ->orderByDesc('id')
            ->toBase()
            ->select('email_conversations.id')
            ->limit(ConversationView::COUNT_CAP);

        return DB::query()->fromSub($matches, 'capped')->selectRaw('count(*) as count');
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
            ->selectSub($this->viewerSyncedCopy($viewer), 'viewer_has_synced_copy')
            ->selectSub($this->viewerMessageShareTier($viewer), 'viewer_message_share_tier')
            ->with(['participants', 'shares', 'user'])
            ->oldest('sent_at')
            ->oldest('id')
            ->get();
    }

    // A link may name any copy of a message; the viewer opens their own preferred copy of it.
    public function viewerCopy(User $viewer, Email $email): ?Email
    {
        if (blank($email->conversation_key)) {
            return null;
        }

        $copies = $this->messages($viewer, (string) $email->conversation_key);

        return filled($email->rfc_message_id)
            ? $copies->firstWhere('rfc_message_id', $email->rfc_message_id)
            : $copies->first(fn (Email $copy): bool => $copy->is($email));
    }

    /**
     * @param  VisibleEmailScope<Model>  $visible
     * @return Builder<EmailConversation>
     */
    private function conversations(User $viewer, VisibleEmailScope $visible, ConversationView $view, ConversationFilters $filters): Builder
    {
        $query = EmailConversation::query()
            ->where('email_conversations.workspace_id', $viewer->current_workspace_id)
            ->whereExists($this->visibleCopiesOf($viewer, $visible, $filters)
                ->whereColumn('emails.conversation_key', 'email_conversations.conversation_key')
                ->toBase())
            // A literal, never a binding: a generic plan cannot match a bound boolean to the partial indexes.
            ->whereRaw($view->includesAutomated() ? 'email_conversations.is_automated' : 'not email_conversations.is_automated')
            ->when($view->requiresOwnCopy(), fn (Builder $conversations): Builder => $conversations
                ->whereExists($this->ownCopies($viewer, $visible)->toBase()));

        return match ($view) {
            ConversationView::NeedsReply => $this->needsReply($query),
            ConversationView::AwaitingReply => $this->awaitingReply($query, $viewer, $visible),
            ConversationView::Unlinked => $this->unlinked($query),
            ConversationView::All, ConversationView::Automated => $query,
        };
    }

    /**
     * @param  VisibleEmailScope<Model>  $visible
     * @return Builder<Email>
     */
    private function ownCopies(User $viewer, VisibleEmailScope $visible): Builder
    {
        return $visible->excludeBlocked($this->delivered(Email::query()))
            ->whereColumn('emails.workspace_id', 'email_conversations.workspace_id')
            ->whereColumn('emails.conversation_key', 'email_conversations.conversation_key')
            ->where('emails.user_id', $viewer->getKey());
    }

    /**
     * @param  Builder<EmailConversation>  $query
     * @return Builder<EmailConversation>
     */
    private function needsReply(Builder $query): Builder
    {
        return $query
            ->whereNotNull('email_conversations.last_external_inbound_at')
            ->where(fn (Builder $unanswered): Builder => $unanswered
                ->whereNull('email_conversations.last_member_outbound_at')
                ->orWhereColumn('email_conversations.last_external_inbound_at', '>', 'email_conversations.last_member_outbound_at'));
    }

    /**
     * @param  Builder<EmailConversation>  $query
     * @param  VisibleEmailScope<Model>  $visible
     * @return Builder<EmailConversation>
     */
    private function awaitingReply(Builder $query, User $viewer, VisibleEmailScope $visible): Builder
    {
        // Whole business days on the viewer's calendar; a weekend counts back from Friday, never as one.
        $today = now()->setTimezone($viewer->effectiveTimezone());
        $cutoff = ($today->isWeekend() ? $today->previousWeekday() : $today)
            ->subWeekdays(ConversationView::AWAITING_REPLY_BUSINESS_DAYS)
            ->endOfDay()
            ->utc();

        return $query
            ->where('email_conversations.last_member_outbound_at', '<=', $cutoff)
            ->where(fn (Builder $unanswered): Builder => $unanswered
                ->whereNull('email_conversations.last_external_inbound_at')
                ->orWhereColumn('email_conversations.last_external_inbound_at', '<', 'email_conversations.last_member_outbound_at'))
            ->whereExists($this->ownCopies($viewer, $visible)
                ->where('emails.direction', EmailDirection::OUTBOUND)
                ->whereColumn('emails.sent_at', 'email_conversations.last_member_outbound_at')
                ->toBase());
    }

    /**
     * @param  Builder<EmailConversation>  $query
     * @return Builder<EmailConversation>
     */
    private function unlinked(Builder $query): Builder
    {
        return $query->whereNotExists(fn (BaseBuilder $link): BaseBuilder => $link
            ->from('emailables')
            ->join('emails', 'emails.id', '=', 'emailables.email_id')
            ->whereNull('emails.deleted_at')
            ->whereColumn('emails.workspace_id', 'email_conversations.workspace_id')
            ->whereColumn('emails.conversation_key', 'email_conversations.conversation_key'));
    }

    /**
     * @param  VisibleEmailScope<Model>  $visible
     * @return Builder<Email>
     */
    private function visibleCopiesOf(User $viewer, VisibleEmailScope $visible, ConversationFilters $filters): Builder
    {
        $query = $this->delivered(Email::query()->withGlobalScope('visible', $visible))
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
     * @param  VisibleEmailScope<Model>  $visible
     * @return Collection<int, ConversationRow>
     */
    private function rows(User $viewer, VisibleEmailScope $visible, Collection $conversations): Collection
    {
        $keys = array_values($conversations->map(fn (EmailConversation $conversation): string => $conversation->conversation_key)->all());

        if ($keys === []) {
            return new Collection;
        }

        $copies = $this->newestVisibleCopies($viewer, $visible, $keys)->keyBy('conversation_key');
        $stats = $this->visibleMessageStats($viewer, $visible, $keys);
        $senders = $this->senderNames($viewer, $visible, $keys);
        $records = $this->linkedRecords($viewer, $visible, $keys);
        $unread = array_flip($this->unreadKeys($viewer, $visible, $keys));

        return $conversations
            ->map(function (EmailConversation $conversation) use ($viewer, $copies, $stats, $senders, $records, $unread): ?ConversationRow {
                $key = $conversation->conversation_key;
                $copy = $copies->get($key);
                $copyStats = $stats->get($key);
                $access = $copy instanceof Email ? $this->privacy->effectiveTier($copy, $viewer) : null;

                if (! $copy instanceof Email || $copyStats === null || ! $access instanceof EmailPrivacyTier) {
                    return null;
                }

                return $this->row($viewer, $copy, $access, $copyStats, $senders[$key] ?? [], $records[$key] ?? [], isset($unread[$key]));
            })
            ->filter()
            ->values();
    }

    /**
     * @param  object{message_count: int|string, last_message_at: string, has_attachments: bool}  $stats
     * @param  list<string>  $senderNames
     * @param  list<array{type: string, id: string, name: string}>  $records
     */
    private function row(User $viewer, Email $copy, EmailPrivacyTier $access, object $stats, array $senderNames, array $records, bool $isUnread): ConversationRow
    {
        $isTeammateCopy = $copy->user_id !== $viewer->getKey();
        $roles = $isTeammateCopy
            ? [EmailParticipantRole::FROM, EmailParticipantRole::TO]
            : [EmailParticipantRole::FROM, EmailParticipantRole::TO, EmailParticipantRole::BCC];

        return new ConversationRow(
            conversationKey: (string) $copy->conversation_key,
            viewerCopyEmailId: $copy->getKey(),
            subject: $access->showsSubject() ? $copy->subject : null,
            snippet: $access->showsBody() ? $copy->snippet : null,
            senderNames: $senderNames,
            participants: $this->names($copy->participants->whereIn('role', $roles)),
            messageCount: (int) $stats->message_count,
            lastMessageAt: CarbonImmutable::parse($stats->last_message_at, 'UTC'),
            hasAttachments: (bool) $stats->has_attachments,
            isUnread: $isUnread,
            ownerUserId: $isTeammateCopy ? $copy->user_id : null,
            ownerName: $isTeammateCopy ? $copy->user?->name : null,
            access: $access,
            viewerHasPendingRequest: (bool) $copy->getAttribute('viewer_has_pending_access_request'),
            records: $records,
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
     * @param  VisibleEmailScope<Model>  $visible
     * @return EloquentCollection<int, Email>
     */
    private function newestVisibleCopies(User $viewer, VisibleEmailScope $visible, array $keys): EloquentCollection
    {
        return $this->visibleCopiesOf($viewer, $visible, new ConversationFilters)
            ->whereIn('emails.conversation_key', $keys)
            ->selectRaw('distinct on (emails.conversation_key) emails.*')
            ->withRecordLinkState()
            ->selectSub($this->viewerSyncedCopy($viewer), 'viewer_has_synced_copy')
            ->selectSub($this->viewerMessageShareTier($viewer), 'viewer_message_share_tier')
            ->withExists(['accessRequests as viewer_has_pending_access_request' => fn (Builder $requests): Builder => $requests
                ->where('requester_id', $viewer->getKey())
                ->scopes(['open'])])
            ->with(['participants', 'shares', 'user'])
            ->orderBy('emails.conversation_key')
            ->orderByRaw('max(emails.sent_at) over (partition by emails.conversation_key, coalesce(emails.rfc_message_id, emails.id::text)) desc')
            ->orderByRaw('coalesce(emails.rfc_message_id, emails.id::text) desc')
            ->orderByRaw('case when emails.user_id = ? then 0 else 1 end', [$viewer->getKey()])
            ->orderByRaw('array_position(?::text[], emails.privacy_tier::text) desc nulls last', [EmailPrivacyTier::opennessRanking()])
            ->orderByDesc('emails.sent_at')
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

    private function viewerMessageShareTier(User $viewer): BaseBuilder
    {
        return DB::table('email_shares')
            ->join('emails as shared_copies', 'shared_copies.id', '=', 'email_shares.email_id')
            ->join('connected_accounts as shared_copy_accounts', 'shared_copy_accounts.id', '=', 'shared_copies.connected_account_id')
            ->whereNull('shared_copies.deleted_at')
            ->whereNull('shared_copy_accounts.deleted_at')
            ->whereColumn('email_shares.workspace_id', 'emails.workspace_id')
            ->whereColumn('shared_copies.workspace_id', 'emails.workspace_id')
            ->whereColumn('shared_copies.rfc_message_id', 'emails.rfc_message_id')
            ->where('email_shares.shared_with', $viewer->getKey())
            ->select('email_shares.tier')
            ->orderByRaw('array_position(?::text[], email_shares.tier::text) desc nulls last', [EmailPrivacyTier::opennessRanking()])
            ->limit(1);
    }

    /**
     * @param  list<string>  $keys
     * @param  VisibleEmailScope<Model>  $visible
     * @return Collection<string, object{message_count: int|string, last_message_at: string, has_attachments: bool}>
     */
    private function visibleMessageStats(User $viewer, VisibleEmailScope $visible, array $keys): Collection
    {
        /** @var Collection<string, object{message_count: int|string, last_message_at: string, has_attachments: bool}> */
        return $this->visibleCopiesOf($viewer, $visible, new ConversationFilters)
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

    /**
     * @param  list<string>  $keys
     * @param  VisibleEmailScope<Model>  $visible
     * @return Builder<Email>
     */
    private function visibleCopyIds(User $viewer, VisibleEmailScope $visible, array $keys): Builder
    {
        return $this->visibleCopiesOf($viewer, $visible, new ConversationFilters)
            ->whereIn('emails.conversation_key', $keys)
            ->select('emails.id');
    }

    /**
     * @param  list<string>  $keys
     * @param  VisibleEmailScope<Model>  $visible
     * @return array<string, list<string>>
     */
    private function senderNames(User $viewer, VisibleEmailScope $visible, array $keys): array
    {
        $ownAddresses = $this->ownAddresses($viewer);
        $senders = DB::table('email_participants')
            ->join('emails', 'emails.id', '=', 'email_participants.email_id')
            ->whereIn('email_participants.email_id', $this->visibleCopyIds($viewer, $visible, $keys)->toBase())
            ->where('email_participants.role', EmailParticipantRole::FROM->value)
            ->select('emails.conversation_key', 'email_participants.name', 'email_participants.email_address')
            ->latest('emails.sent_at')
            ->orderByDesc('emails.id')
            ->get();

        $names = [];
        $seen = [];

        foreach ($senders as $sender) {
            $key = (string) $sender->conversation_key;
            $address = Str::lower((string) $sender->email_address);

            if (isset($seen[$key][$address]) || count($names[$key] ?? []) >= 3) {
                continue;
            }

            $seen[$key][$address] = true;
            $names[$key][] = in_array($address, $ownAddresses, true)
                ? __('filament/pages/email-inbox.conversation_row.me')
                : (string) ($sender->name ?: $sender->email_address);
        }

        return $names;
    }

    /** @return list<string> */
    private function ownAddresses(User $viewer): array
    {
        return array_values(ConnectedAccount::query()
            ->where('workspace_id', $viewer->current_workspace_id)
            ->where('user_id', $viewer->getKey())
            ->pluck('email_address')
            ->push($viewer->email)
            ->map(fn (string $address): string => Str::lower($address))
            ->all());
    }

    /**
     * @param  list<string>  $keys
     * @param  VisibleEmailScope<Model>  $visible
     * @return array<string, list<array{type: string, id: string, name: string}>>
     */
    private function linkedRecords(User $viewer, VisibleEmailScope $visible, array $keys): array
    {
        $order = [CrmEntity::Company, CrmEntity::Opportunity, CrmEntity::People];
        $links = DB::table('emailables')
            ->join('emails', 'emails.id', '=', 'emailables.email_id')
            ->whereIn('emailables.email_id', $this->visibleCopyIds($viewer, $visible, $keys)->toBase())
            ->whereIn('emailables.emailable_type', array_map(fn (CrmEntity $entity): string => $entity->value, $order))
            ->distinct()
            ->get(['emails.conversation_key', 'emailables.emailable_type', 'emailables.emailable_id']);

        $names = $this->recordNames($viewer, $links);
        $records = [];

        foreach ($links as $link) {
            $name = $names[$link->emailable_type][$link->emailable_id] ?? null;

            if ($name !== null) {
                $records[(string) $link->conversation_key][] = ['type' => (string) $link->emailable_type, 'id' => (string) $link->emailable_id, 'name' => $name];
            }
        }

        $rank = array_flip(array_map(fn (CrmEntity $entity): string => $entity->value, $order));

        return array_map(fn (array $linked): array => array_slice(
            collect($linked)->sortBy([fn (array $a, array $b): int => $rank[$a['type']] <=> $rank[$b['type']], fn (array $a, array $b): int => strcasecmp($a['name'], $b['name'])])->values()->all(),
            0,
            3,
        ), $records);
    }

    /**
     * @param  Collection<int, \stdClass>  $links
     * @return array<string, array<string, string>>
     */
    private function recordNames(User $viewer, Collection $links): array
    {
        $names = [];

        foreach ($links->groupBy('emailable_type') as $type => $typed) {
            $entity = CrmEntity::from((string) $type);

            $names[$entity->value] = $entity->model()::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $viewer->current_workspace_id)
                ->whereKey($typed->pluck('emailable_id')->unique()->values()->all())
                ->pluck($entity->titleColumn(), 'id')
                ->map(fn (mixed $name): string => (string) $name)
                ->all();
        }

        return $names;
    }

    /**
     * @param  list<string>  $keys
     * @param  VisibleEmailScope<Model>  $visible
     * @return list<string>
     */
    private function unreadKeys(User $viewer, VisibleEmailScope $visible, array $keys): array
    {
        return array_values($this->visibleCopiesOf($viewer, $visible, new ConversationFilters)
            ->whereIn('emails.conversation_key', $keys)
            ->where('emails.direction', EmailDirection::INBOUND)
            ->where(fn (Builder $reached): Builder => $reached
                ->where('emails.user_id', $viewer->getKey())
                ->orWhereExists(fn (BaseBuilder $share): BaseBuilder => $share
                    ->from('email_shares')
                    ->whereColumn('email_shares.email_id', 'emails.id')
                    ->where('email_shares.shared_with', $viewer->getKey())
                    ->where('email_shares.tier', EmailPrivacyTier::FULL->value)))
            ->whereNotExists(fn (BaseBuilder $read): BaseBuilder => $read
                ->from('email_reads')
                ->whereColumn('email_reads.email_id', 'emails.id')
                ->where('email_reads.user_id', $viewer->getKey()))
            ->toBase()
            ->distinct()
            ->pluck('emails.conversation_key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all());
    }
}
