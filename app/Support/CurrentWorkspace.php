<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Workspace;
use Closure;
use Illuminate\Support\Facades\Context;
use Relaticle\CustomFields\Services\TenantContextService;

final class CurrentWorkspace
{
    private const string KEY = 'current_workspace_id';

    private ?Workspace $workspace = null;

    private bool $readsAcrossWorkspaces = false;

    public function set(Workspace $workspace): void
    {
        $this->workspace = $workspace;
        Context::addHidden(self::KEY, $workspace->getKey());
    }

    public function forget(): void
    {
        $this->workspace = null;
        Context::forgetHidden(self::KEY);
    }

    public function get(): ?Workspace
    {
        if ($this->workspace instanceof Workspace) {
            return $this->workspace;
        }

        $workspaceId = Context::getHidden(self::KEY);

        if (! is_string($workspaceId)) {
            return null;
        }

        return $this->workspace = Workspace::query()->find($workspaceId);
    }

    public function readAcrossWorkspaces(): void
    {
        $this->readsAcrossWorkspaces = true;
    }

    public function readsAcrossWorkspaces(): bool
    {
        return $this->readsAcrossWorkspaces;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function within(Workspace|string $workspace, Closure $callback): mixed
    {
        $workspace = $workspace instanceof Workspace ? $workspace : Workspace::query()->findOrFail($workspace);
        $previousWorkspace = $this->get();
        $previousTenantId = TenantContextService::getCurrentTenantId();

        $this->set($workspace);
        // Unbound, the custom-fields TenantScope no-ops and spans every tenant.
        TenantContextService::setTenantId($workspace->getKey());

        try {
            return $callback();
        } finally {
            $previousWorkspace instanceof Workspace ? $this->set($previousWorkspace) : $this->forget();
            TenantContextService::setTenantId($previousTenantId);
        }
    }
}
