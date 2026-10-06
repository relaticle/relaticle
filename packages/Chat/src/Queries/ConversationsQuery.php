<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\TitleSanitizer;
use stdClass;

final readonly class ConversationsQuery
{
    private const array COLUMNS = ['id', 'title', 'created_at', 'updated_at'];

    private const int LIMIT = 50;

    public function find(User $user, string $conversationId): ?stdClass
    {
        return AgentConversation::query()
            ->ownedBy($user)
            ->whereKey($conversationId)
            ->toBase()
            ->first(self::COLUMNS);
    }

    /** @return Collection<int, stdClass> */
    public function recent(User $user, int $limit = self::LIMIT): Collection
    {
        return $this->newest(AgentConversation::query()->ownedBy($user), $limit);
    }

    /** @return Collection<int, stdClass> */
    public function search(User $user, string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return collect();
        }

        $needle = '%'.LikePattern::escape($term).'%';

        return $this->newest(
            AgentConversation::query()
                ->ownedBy($user)
                ->where(function (Builder $conversation) use ($needle): void {
                    $conversation->where('title', 'ilike', $needle)
                        ->orWhereHas('messages', fn (Builder $message): Builder => $message->withoutSynthetic()->where('content', 'ilike', $needle));
                }),
            self::LIMIT,
        );
    }

    /**
     * @param  Builder<AgentConversation>  $conversations
     * @return Collection<int, stdClass>
     */
    private function newest(Builder $conversations, int $limit): Collection
    {
        return $conversations
            ->latest('updated_at')
            ->limit($limit)
            ->toBase()
            ->get(self::COLUMNS)
            ->map(function (stdClass $row): stdClass {
                $row->title = TitleSanitizer::clean((string) $row->title);

                return $row;
            });
    }
}
