<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailParticipant;

final readonly class RefreshConversationSummary
{
    public function execute(string $workspaceId, string $conversationKey): void
    {
        DB::transaction(function () use ($workspaceId, $conversationKey): void {
            // Serialises refreshes of one conversation, so each reads every copy committed before it.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["{$workspaceId}:{$conversationKey}"]);

            $this->write($workspaceId, $conversationKey);
        });
    }

    private function write(string $workspaceId, string $conversationKey): void
    {
        $totals = $this->messages($workspaceId, $conversationKey)->toBase()
            ->selectRaw('count(distinct coalesce(rfc_message_id, id::text)) as message_count')
            ->selectRaw('min(sent_at) as first_message_at, max(sent_at) as last_message_at')
            ->selectRaw('coalesce(bool_or(has_attachments), false) as has_attachments')
            ->first();

        if ((int) ($totals->message_count ?? 0) === 0) {
            EmailConversation::query()->where('workspace_id', $workspaceId)->where('conversation_key', $conversationKey)->delete();

            return;
        }

        $members = $this->memberAddresses($workspaceId);

        EmailConversation::query()->upsert([[
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspaceId,
            'conversation_key' => $conversationKey,
            'message_count' => (int) $totals->message_count,
            'first_message_at' => $totals->first_message_at,
            'last_message_at' => $totals->last_message_at,
            'last_external_inbound_at' => $this->externalInbound($workspaceId, $conversationKey, $members)->max('sent_at'),
            'last_member_outbound_at' => $this->memberMessages($workspaceId, $conversationKey, $members)->max('sent_at'),
            'has_attachments' => (bool) $totals->has_attachments,
            'is_automated' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['workspace_id', 'conversation_key'], [
            'message_count', 'first_message_at', 'last_message_at', 'last_external_inbound_at',
            'last_member_outbound_at', 'has_attachments', 'is_automated', 'updated_at',
        ]);
    }

    /** @return Builder<Email> */
    private function messages(string $workspaceId, string $conversationKey): Builder
    {
        return Email::query()
            ->where('workspace_id', $workspaceId)
            ->where('conversation_key', $conversationKey)
            ->whereIn('status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->whereNotNull('sent_at');
    }

    /**
     * @param  list<string>  $members
     * @return Builder<Email>
     */
    private function externalInbound(string $workspaceId, string $conversationKey, array $members): Builder
    {
        return $this->messages($workspaceId, $conversationKey)
            ->where('direction', EmailDirection::INBOUND)
            ->whereDoesntHave('participants', fn (Builder $query): Builder => $this->sentBy($query, $members));
    }

    /**
     * @param  list<string>  $members
     * @return Builder<Email>
     */
    private function memberMessages(string $workspaceId, string $conversationKey, array $members): Builder
    {
        return $this->messages($workspaceId, $conversationKey)->where(fn (Builder $query): Builder => $query
            ->where('direction', EmailDirection::OUTBOUND)
            ->orWhereHas('participants', fn (Builder $participants): Builder => $this->sentBy($participants, $members)));
    }

    /**
     * @param  Builder<EmailParticipant>  $query
     * @param  list<string>  $members
     * @return Builder<EmailParticipant>
     */
    private function sentBy(Builder $query, array $members): Builder
    {
        return $query->where('role', EmailParticipantRole::FROM->value)->whereIn(DB::raw('lower(email_address)'), $members);
    }

    /** @return list<string> */
    private function memberAddresses(string $workspaceId): array
    {
        $members = DB::table('users')->selectRaw('lower(email) as address')
            ->whereIn('id', DB::table('workspace_user')->select('user_id')->where('workspace_id', $workspaceId))
            ->orWhereIn('id', DB::table('workspaces')->select('user_id')->where('id', $workspaceId));

        return array_values(DB::table('connected_accounts')->selectRaw('lower(email_address) as address')
            ->where('workspace_id', $workspaceId)
            ->union($members)
            ->pluck('address')
            ->all());
    }
}
