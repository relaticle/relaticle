<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Actions;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class EndWorkspaceTrial
{
    public function __construct(private RecordTrialChange $recordTrialChange) {}

    public function execute(SystemAdministrator $admin, Workspace $workspace): void
    {
        abort_unless(Gate::forUser($admin)->allows('endTrial', $workspace), 403);

        DB::transaction(function () use ($admin, $workspace): void {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();

            abort_unless(Gate::forUser($admin)->allows('endTrial', $locked), 403);

            $before = $locked->only(['trial_ends_at']);

            $locked->forceFill(['trial_ends_at' => now()])->save();

            $this->recordTrialChange->execute($admin, $locked, 'trial_ended', $before);
        });

        $workspace->refresh();
    }
}
