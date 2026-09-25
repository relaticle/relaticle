<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Support\TitleSanitizer;

final readonly class ListConversations
{
    /**
     * @return Collection<int, \stdClass>
     */
    public function execute(User $user, int $limit = 50): Collection
    {
        return AgentConversation::query()
            ->ownedBy($user)
            ->latest('updated_at')
            ->limit($limit)
            ->toBase()
            ->get(['id', 'title', 'created_at', 'updated_at'])
            ->map(function (\stdClass $row): \stdClass {
                $row->title = TitleSanitizer::clean((string) $row->title);

                return $row;
            });
    }
}
