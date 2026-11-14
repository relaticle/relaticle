<?php

declare(strict_types=1);

namespace Relaticle\Chat\Listeners;

use App\Models\Workspace;
use Laravel\Ai\Events\InvokingTool;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Exceptions\MembershipRevokedException;

/**
 * laravel/ai dispatches InvokingTool outside the tool's own try/catch, so a throw
 * here ends the run instead of reaching the model as a tool failure.
 */
final readonly class EnsureMemberOnToolCallListener
{
    public function handle(InvokingTool $event): void
    {
        $agent = $event->agent;

        if (! $agent instanceof CrmAssistant || ! $agent->workspace instanceof Workspace || $agent->currentUser === null) {
            return;
        }

        if (! $agent->workspace->hasMember($agent->currentUser['id'])) {
            throw MembershipRevokedException::forWorkspace((string) $agent->workspace->getKey());
        }
    }
}
