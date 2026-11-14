<?php

declare(strict_types=1);

namespace Relaticle\Chat\Listeners;

use App\Models\Workspace;
use Laravel\Ai\Events\InvokingTool;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Exceptions\MembershipRevokedException;

final readonly class EnsureMemberOnToolCallListener
{
    public function handle(InvokingTool $event): void
    {
        $agent = $event->agent;

        if (! $agent instanceof CrmAssistant || ! $agent->workspace instanceof Workspace || $agent->currentUser === null) {
            return;
        }

        // laravel/ai dispatches InvokingTool outside the tool's try/catch, so this throw ends the run.
        if (! $agent->workspace->hasMemberNow($agent->currentUser['id'])) {
            throw MembershipRevokedException::forWorkspace((string) $agent->workspace->getKey());
        }
    }
}
