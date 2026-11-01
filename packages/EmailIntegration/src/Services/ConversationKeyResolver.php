<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\MessageIdList;

final readonly class ConversationKeyResolver
{
    public function resolve(FetchedEmailData $data, ConnectedAccount $account): string
    {
        return $this->resolveFor(
            (string) $account->workspace_id,
            (string) $account->getKey(),
            $data->threadId,
            $data->rfcMessageId,
            MessageIdList::parents($data->inReplyTo, $data->references),
            MessageIdList::root($data->inReplyTo, $data->references, $data->rfcMessageId) ?? "{$account->getKey()}:{$data->providerMessageId}",
            null,
        );
    }

    public function resolveStored(Email $email): string
    {
        return $this->resolveFor(
            $email->workspace_id,
            $email->connected_account_id,
            (string) $email->thread_id,
            $email->rfc_message_id,
            MessageIdList::parents($email->in_reply_to, $email->references),
            MessageIdList::root($email->in_reply_to, $email->references, $email->rfc_message_id) ?? $email->getKey(),
            $email->getKey(),
        );
    }

    /** @return list<string> keys held by this message's other copies, its stored parents, stored replies to it, or its provider thread */
    public function keysToMerge(Email $email): array
    {
        if ($email->conversation_key === null) {
            return [];
        }

        $keys = [...$this->linkedKeys($email), $this->joinedKey($email)];

        return array_values(array_unique(array_filter(
            $keys,
            fn (?string $key): bool => $key !== null && $key !== $email->conversation_key,
        )));
    }

    public static function keyFor(string $workspaceId, string $root): string
    {
        return hash('sha256', $workspaceId.$root);
    }

    /** @param  list<string>  $parents */
    private function resolveFor(string $workspaceId, string $accountId, string $threadId, ?string $rfcMessageId, array $parents, string $root, ?string $exceptId): string
    {
        $keyed = fn (): Builder => $this->keyed($workspaceId, $exceptId)->orderBy('conversation_key');

        return $this->keyOfParent($keyed(), $parents)
            ?? ($threadId === '' ? null : $keyed()->where('connected_account_id', $accountId)->where('thread_id', $threadId)->value('conversation_key'))
            ?? ($rfcMessageId === null ? null : $keyed()->where('rfc_message_id', $rfcMessageId)->value('conversation_key'))
            ?? self::keyFor($workspaceId, $root);
    }

    /** @return list<string> */
    private function linkedKeys(Email $email): array
    {
        if ($email->rfc_message_id === null) {
            return [];
        }

        $keys = $this->keyed($email->workspace_id, $email->getKey())
            ->where('conversation_key', '!=', $email->conversation_key)
            ->where(fn (Builder $query): Builder => $query
                ->where('rfc_message_id', $email->rfc_message_id)
                ->orWhere('in_reply_to', $email->rfc_message_id)
                ->orWhereRaw('string_to_array("references", \' \') @> array[?]::text[]', [$email->rfc_message_id])
                ->orWhereIn('rfc_message_id', MessageIdList::parents($email->in_reply_to, $email->references)))
            ->distinct()
            ->pluck('conversation_key')
            ->all();

        return array_values(array_map(strval(...), $keys));
    }

    private function joinedKey(Email $email): ?string
    {
        // A stored parent outranks the provider thread, as it does in resolveFor().
        $keyed = fn (): Builder => $this->keyed($email->workspace_id, $email->getKey())->orderBy('conversation_key');
        $parentKey = $this->keyOfParent($keyed(), MessageIdList::parents($email->in_reply_to, $email->references));

        if ($parentKey !== null || blank($email->thread_id)) {
            return $parentKey;
        }

        $threadKey = $keyed()
            ->where('connected_account_id', $email->connected_account_id)
            ->where('thread_id', $email->thread_id)
            ->where('conversation_key', '!=', $email->conversation_key)
            ->value('conversation_key');

        return is_string($threadKey) ? $threadKey : null;
    }

    /**
     * @param  Builder<Email>  $keyed
     * @param  list<string>  $parents
     */
    private function keyOfParent(Builder $keyed, array $parents): ?string
    {
        if ($parents === []) {
            return null;
        }

        $keys = $keyed->whereIn('rfc_message_id', $parents)->pluck('conversation_key', 'rfc_message_id');

        return collect($parents)->map(fn (string $id): ?string => $keys->get($id))->filter()->first();
    }

    /** @return Builder<Email> */
    private function keyed(string $workspaceId, ?string $exceptId): Builder
    {
        return Email::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('conversation_key')
            ->when($exceptId !== null, fn (Builder $query): Builder => $query->whereKeyNot($exceptId));
    }
}
