<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\CreationSource;
use App\Features\SetupConversation;
use App\Jobs\OpenDataArrivedTurn;
use App\Models\Company;
use App\Models\People;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

final readonly class ScheduleAssistantArrivalTurn
{
    // An assistant writes one record per call and never says it is done.
    private const int DELAY_MINUTES = 10;

    private const string SOURCE_KEY = 'mcp';

    public function execute(Company|People $record): void
    {
        if ($record->creation_source !== CreationSource::MCP) {
            return;
        }

        $workspaceId = (string) $record->workspace_id;

        if (! Cache::add("chat:assistant-arrival-scheduled:{$workspaceId}", true, now()->addDay())) {
            return;
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace
            || ! Feature::active(SetupConversation::class)
            || ! $workspace->isPersonalWorkspace()
            || ! $workspace->isInSetupWindow()) {
            return;
        }

        dispatch(new OpenDataArrivedTurn($workspaceId, self::SOURCE_KEY))->delay(now()->addMinutes(self::DELAY_MINUTES));
    }
}
