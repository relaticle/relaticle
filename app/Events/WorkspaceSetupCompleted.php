<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Workspace;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkspaceSetupCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Workspace $workspace) {}
}
