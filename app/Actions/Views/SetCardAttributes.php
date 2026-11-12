<?php

declare(strict_types=1);

namespace App\Actions\Views;

use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\BoardCardAttributesQuery;
use Closure;

final readonly class SetCardAttributes
{
    public function __construct(
        private SaveBoardState $saveBoardState,
        private BoardCardAttributesQuery $cardAttributes,
    ) {}

    /**
     * @param  array<array-key, mixed>|Closure(list<string>): array<array-key, mixed>  $keys
     */
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, array|Closure $keys, ?SavedView $view = null): BoardStateData
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        $offered = $this->cardAttributes->get($user, $workspace, $entity);

        return $this->saveBoardState->execute(
            $user,
            $workspace,
            $entity,
            function (BoardStateData $stored) use ($keys, $offered, $entity): array {
                $kept = $keys instanceof Closure ? ($stored->cardAttributes ?? $entity->defaultCardAttributes()) : [];
                $next = $keys instanceof Closure ? $keys($kept) : $keys;

                abort_unless(array_all($next, static fn (mixed $key): bool => is_string($key)), 422);
                abort_if(array_diff($next, $kept, $offered) !== [], 422);

                return ['card_attributes' => array_values(array_unique($next))];
            },
            $view,
        );
    }
}
