<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Contracts\LinkActorResolverInterface;

/**
 * Who a relationship link is stamped as having been created by.
 *
 * The signed-in user answers for the panel and the API. A queued job and a chat approval
 * have no session, so the path that knows the actor names it for the duration of its own
 * write and puts the previous one back: a worker keeps this singleton alive between jobs.
 */
final class LinkActorResolver implements LinkActorResolverInterface
{
    private ?Model $actor = null;

    public function resolve(): ?Model
    {
        if ($this->actor instanceof Model) {
            return $this->actor;
        }

        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * Name the actor for the writes that follow, returning the one it replaced so the
     * caller can restore it in a finally block.
     */
    public function override(?Model $actor): ?Model
    {
        $previous = $this->actor;

        $this->actor = $actor;

        return $previous;
    }
}
