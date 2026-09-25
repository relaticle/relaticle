<?php

declare(strict_types=1);

namespace App\Http\Controllers\Impersonation;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Impersonation\Impersonator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final readonly class StartImpersonationController
{
    public function __construct(private Impersonator $impersonator) {}

    public function __invoke(Request $request, string $user): RedirectResponse
    {
        $administrator = $this->impersonator->administrator($request->query('administrator'));

        abort_if($administrator === null, 403);
        abort_unless(Gate::forUser($administrator)->allows('impersonate'), 403);

        $target = User::query()->findOrFail($user);

        abort_unless($this->impersonator->claim($request), 403);

        $this->impersonator->stop($request);
        $this->impersonator->record('impersonation_started', $administrator, $target);
        $this->impersonator->start($request, (string) $administrator->getAuthIdentifier(), $target);

        return redirect()->to(url()->getAppUrl($this->landingPath($request, $target)));
    }

    /**
     * The workspace arrives as a signed query parameter, so it cannot be tampered
     * with, but the owner may have left the workspace since the link was built.
     */
    private function landingPath(Request $request, User $target): string
    {
        $workspaceId = $request->query('workspace');

        if (! is_string($workspaceId)) {
            return '';
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace || ! $target->belongsToWorkspace($workspace)) {
            return '';
        }

        return $workspace->slug;
    }
}
