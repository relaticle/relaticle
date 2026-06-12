<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Support\TitleSanitizer;

final readonly class ListConversations
{
    /**
     * @return Collection<int, \stdClass>
     */
    public function execute(User $user, int $limit = 50): Collection
    {
        return DB::table('agent_conversations')
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->current_team_id)
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'created_at', 'updated_at'])
            ->map(function (\stdClass $row): \stdClass {
                $row->title = TitleSanitizer::clean((string) $row->title);

                return $row;
            });
    }
}
