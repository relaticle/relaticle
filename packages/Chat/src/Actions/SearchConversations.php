<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $query).'%';

        return DB::table('agent_conversations as ac')
            ->select(['ac.id', 'ac.title', 'ac.created_at', 'ac.updated_at'])
            ->where('ac.user_id', (string) $user->getKey())
            ->where('ac.team_id', (string) $user->current_team_id)
            ->where(function (Builder $q) use ($needle): void {
                $q->where('ac.title', 'ilike', $needle)
                    ->orWhereExists(function (Builder $sub) use ($needle): void {
                        $sub->from('agent_conversation_messages as m')
                            ->whereColumn('m.conversation_id', 'ac.id')
                            ->where('m.content', 'ilike', $needle);
                    });
            })
            ->latest('ac.updated_at')
            ->limit(50)
            ->get()
            ->map(function (\stdClass $row): \stdClass {
                $row->title = TitleSanitizer::clean((string) $row->title);

                return $row;
            });
    }
}
