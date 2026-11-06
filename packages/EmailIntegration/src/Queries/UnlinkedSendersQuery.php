<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator as CursorPaginatorPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Relaticle\EmailIntegration\Data\UnlinkedSender;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use stdClass;

final readonly class UnlinkedSendersQuery
{
    /** @return CursorPaginator<int, UnlinkedSender> */
    public function paginate(User $viewer, int $perPage = 25, ?string $cursor = null): CursorPaginator
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        $visible = new VisibleEmailScope($viewer);
        $senders = DB::query()
            ->fromSub($this->senders($viewer, $visible), 'senders')
            ->leftJoinSub($this->sentTo($viewer, $visible), 'sent_to', 'sent_to.address', '=', 'senders.address')
            ->select('senders.*')
            ->selectRaw('coalesce(sent_to.sent_count, 0) as sent_to_count');

        // Ordered on a derived table, so the cursor compares plain columns rather than aggregates.
        $page = DB::query()
            ->fromSub($senders, 'unlinked_senders')
            ->orderByDesc('sent_to_count')
            ->latest('last_message_at')
            ->orderBy('address')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        throw_unless($page instanceof CursorPaginatorPage, LogicException::class, 'The query builder returned an unexpected paginator.');

        return $page->through(fn (stdClass $sender): UnlinkedSender => new UnlinkedSender(
            address: (string) $sender->address,
            name: filled($sender->name) ? (string) $sender->name : null,
            conversationCount: (int) $sender->conversation_count,
            lastMessageAt: CarbonImmutable::parse($sender->last_message_at, 'UTC'),
            sentToCount: (int) $sender->sent_to_count,
        ));
    }

    /**
     * The newest unlinked conversations grouped under the address, the same grouping the sender rows count.
     *
     * @return list<string>
     */
    public function conversationKeys(User $viewer, string $address, int $limit = 20): array
    {
        Gate::forUser($viewer)->authorize('viewAny', Email::class);

        return array_values(DB::query()
            ->fromSub($this->grouped($viewer, new VisibleEmailScope($viewer)), 'grouped')
            ->where('address', Str::lower($address))
            ->groupBy('conversation_key')
            ->orderByRaw('max(sent_at) desc')
            ->orderBy('conversation_key')
            ->limit($limit)
            ->pluck('conversation_key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all());
    }

    /** @param  VisibleEmailScope<Model>  $visible */
    private function senders(User $viewer, VisibleEmailScope $visible): BaseBuilder
    {
        return DB::query()
            ->fromSub($this->grouped($viewer, $visible), 'grouped')
            ->select('address')
            ->selectRaw("(array_agg(name order by sent_at desc) filter (where coalesce(name, '') <> ''))[1] as name")
            ->selectRaw('count(distinct conversation_key) as conversation_count')
            ->selectRaw('max(sent_at) as last_message_at')
            ->groupBy('address');
    }

    /**
     * One row per message from an external sender, plus one row per conversation the viewer only wrote in, under its first external recipient.
     *
     * @param  VisibleEmailScope<Model>  $visible
     */
    private function grouped(User $viewer, VisibleEmailScope $visible): BaseBuilder
    {
        return $this->fromSenders($viewer, $visible)->unionAll($this->toFirstRecipients($viewer, $visible));
    }

    /** @param  VisibleEmailScope<Model>  $visible */
    private function fromSenders(User $viewer, VisibleEmailScope $visible): BaseBuilder
    {
        return $this->receivedUnlinked($this->ownDelivered($viewer, $visible))
            ->join('email_participants', 'email_participants.email_id', '=', 'emails.id')
            ->where('email_participants.role', EmailParticipantRole::FROM->value)
            ->toBase()
            ->selectRaw('lower(email_participants.email_address) as address')
            ->addSelect('email_participants.name', 'emails.conversation_key', 'emails.sent_at');
    }

    /** @param  VisibleEmailScope<Model>  $visible */
    private function toFirstRecipients(User $viewer, VisibleEmailScope $visible): BaseBuilder
    {
        $recipientRoles = [EmailParticipantRole::TO->value, EmailParticipantRole::CC->value, EmailParticipantRole::BCC->value];

        return $this->unlinked($this->ownDelivered($viewer, $visible))
            ->where('emails.direction', EmailDirection::OUTBOUND)
            ->where('emails.is_internal', false)
            ->whereNotIn('emails.conversation_key', $this->receivedUnlinked($this->ownDelivered($viewer, $visible))->select('emails.conversation_key')->toBase())
            ->join('email_participants', 'email_participants.email_id', '=', 'emails.id')
            ->whereIn('email_participants.role', $recipientRoles)
            ->whereNotIn(DB::raw('lower(email_participants.email_address)'), $this->memberAddresses($viewer))
            ->toBase()
            ->selectRaw('distinct on (emails.conversation_key) lower(email_participants.email_address) as address')
            ->addSelect('email_participants.name', 'emails.conversation_key')
            ->selectRaw('max(emails.sent_at) over (partition by emails.conversation_key) as sent_at')
            ->orderBy('emails.conversation_key')
            ->orderBy('emails.sent_at')
            ->orderBy('emails.id')
            ->orderByRaw('case email_participants.role when ? then 0 when ? then 1 else 2 end', [EmailParticipantRole::TO->value, EmailParticipantRole::CC->value])
            ->orderBy('email_participants.id');
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function receivedUnlinked(Builder $query): Builder
    {
        return $this->unlinked($query)
            ->where('emails.direction', EmailDirection::INBOUND)
            ->where('emails.is_internal', false)
            ->whereNull('emails.automated_reason');
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function unlinked(Builder $query): Builder
    {
        return $query
            ->whereNotNull('emails.conversation_key')
            ->whereNotExists(fn (BaseBuilder $link): BaseBuilder => $link
                ->from('emailables')
                ->join('emails as linked_copies', 'linked_copies.id', '=', 'emailables.email_id')
                ->whereNull('linked_copies.deleted_at')
                ->whereColumn('linked_copies.workspace_id', 'emails.workspace_id')
                ->whereColumn('linked_copies.conversation_key', 'emails.conversation_key'));
    }

    private function memberAddresses(User $viewer): BaseBuilder
    {
        return DB::table('users')
            ->selectRaw('lower(users.email)')
            ->where(fn (BaseBuilder $members): BaseBuilder => $members
                ->whereIn('users.id', DB::table((new Membership)->getTable())->where('workspace_id', $viewer->current_workspace_id)->select('user_id'))
                ->orWhereIn('users.id', DB::table('workspaces')->where('id', $viewer->current_workspace_id)->select('user_id')));
    }

    /** @param  VisibleEmailScope<Model>  $visible */
    private function sentTo(User $viewer, VisibleEmailScope $visible): BaseBuilder
    {
        return $this->ownDelivered($viewer, $visible)
            ->where('emails.direction', EmailDirection::OUTBOUND)
            ->join('email_participants', 'email_participants.email_id', '=', 'emails.id')
            ->whereIn('email_participants.role', [EmailParticipantRole::TO->value, EmailParticipantRole::CC->value, EmailParticipantRole::BCC->value])
            ->toBase()
            ->selectRaw('lower(email_participants.email_address) as address')
            ->selectRaw('count(distinct emails.id) as sent_count')
            ->groupByRaw('lower(email_participants.email_address)');
    }

    /**
     * @param  VisibleEmailScope<Model>  $visible
     * @return Builder<Email>
     */
    private function ownDelivered(User $viewer, VisibleEmailScope $visible): Builder
    {
        return Email::query()
            ->withGlobalScope('visible', $visible)
            ->where('emails.workspace_id', $viewer->current_workspace_id)
            ->where('emails.user_id', $viewer->getKey())
            ->whereIn('emails.status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->whereNotNull('emails.sent_at');
    }
}
