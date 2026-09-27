<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\TitleSanitizer;

final readonly class SearchConversations
{
    /**
     * @return Collection<int, \stdClass>
     */
    public function execute(User $user, string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $needle = '%'.LikePattern::escape($query).'%';

        return AgentConversation::query()
            ->ownedBy($user)
            ->where(function (Builder $conversation) use ($needle): void {
                $conversation->where('title', 'ilike', $needle)
                    ->orWhereHas('messages', fn (Builder $message): Builder => $message->withoutSynthetic()->where('content', 'ilike', $needle));
            })
            ->latest('updated_at')
            ->limit(50)
            ->toBase()
            ->get(['id', 'title', 'created_at', 'updated_at'])
            ->map(function (\stdClass $row): \stdClass {
                $row->title = TitleSanitizer::clean((string) $row->title);

                return $row;
            });
    }
}
