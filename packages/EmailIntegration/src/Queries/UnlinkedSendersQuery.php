<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

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

    /** @param  VisibleEmailScope<Model>  $visible */
    private function senders(User $viewer, VisibleEmailScope $visible): BaseBuilder
    {
        return $this->ownDelivered($viewer, $visible)
            ->where('emails.direction', EmailDirection::INBOUND)
            ->where('emails.is_internal', false)
            ->whereNull('emails.automated_reason')
            ->whereNotNull('emails.conversation_key')
            ->whereNotExists(fn (BaseBuilder $link): BaseBuilder => $link
                ->from('emailables')
                ->join('emails as linked_copies', 'linked_copies.id', '=', 'emailables.email_id')
                ->whereNull('linked_copies.deleted_at')
                ->whereColumn('linked_copies.workspace_id', 'emails.workspace_id')
                ->whereColumn('linked_copies.conversation_key', 'emails.conversation_key'))
            ->join('email_participants', 'email_participants.email_id', '=', 'emails.id')
            ->where('email_participants.role', EmailParticipantRole::FROM->value)
            ->toBase()
            ->selectRaw('lower(email_participants.email_address) as address')
            ->selectRaw("(array_agg(email_participants.name order by emails.sent_at desc) filter (where coalesce(email_participants.name, '') <> ''))[1] as name")
            ->selectRaw('count(distinct emails.conversation_key) as conversation_count')
            ->selectRaw('max(emails.sent_at) as last_message_at')
            ->groupByRaw('lower(email_participants.email_address)');
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
