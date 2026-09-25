<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use Relaticle\Chat\Models\PendingAction;
use RuntimeException;

/**
 * The tenant boundary for a proposal, in one place.
 *
 * A proposal is a one-click CRM write, so the workspace that owns it is the security
 * boundary. The Livewire dock already scopes its lookup by workspace and user, but
 * the invariant belongs below the UI: a future caller (an API route, a job, an
 * MCP tool) must not be able to resolve or edit another tenant's proposal.
 *
 * Without it the executed action stamps workspace_id from the actor's current workspace
 * while the custom-field values land under the proposal's, splitting one record
 * across two tenants. ProposalEditor is the sharpest case: it pins the
 * custom-fields tenant to the proposal's workspace and validates core fields against
 * the actor's, so an unguarded call writes across that seam by construction.
 *
 * Every mutating entry point calls this, approving and destructive alike. A
 * guard on only the approving half leaves the half that destroys work open.
 */
final readonly class ProposalOwnership
{
    public static function assert(PendingAction $pendingAction, User $user): void
    {
        throw_unless(
            (string) ($user->currentWorkspace?->getKey() ?? '') === (string) $pendingAction->workspace_id,
            RuntimeException::class,
            'This action belongs to another workspace.',
        );
    }
}
