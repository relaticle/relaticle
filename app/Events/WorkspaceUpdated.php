<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkspaceUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Workspace $workspace) {}
}
