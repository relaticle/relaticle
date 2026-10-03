<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class EndWorkspaceTrial
{
    public function execute(SystemAdministrator $admin, Workspace $workspace): void
    {
        abort_unless(Gate::forUser($admin)->allows('endTrial', $workspace), 403);

        $workspace->forceFill(['trial_ends_at' => now()])->save();
    }
}
