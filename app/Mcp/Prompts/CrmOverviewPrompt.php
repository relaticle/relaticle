<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;

#[Description('Get an overview of the CRM data for the current team, including record counts and recent activity.')]
final class CrmOverviewPrompt extends Prompt
{
    private const int CACHE_TTL = 60;

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $teamId = $user->currentTeam->getKey();
        $cacheKey = "crm_overview_{$teamId}";

        $overview = Cache::remember($cacheKey, self::CACHE_TTL, function (): string {
            $counts = [
                'companies' => Company::query()->count(),
                'people' => People::query()->count(),
                'opportunities' => Opportunity::query()->count(),
                'tasks' => Task::query()->count(),
                'notes' => Note::query()->count(),
            ];

            $recentCompanies = Company::query()
                ->latest()
                ->take(5)
                ->pluck('name')
                ->implode(', ');

            $recentPeople = People::query()
                ->latest()
                ->take(5)
                ->pluck('name')
                ->implode(', ');

            $text = "CRM Overview for current team:\n\n";
            $text .= "Record Counts:\n";

            foreach ($counts as $entity => $count) {
                $text .= "  - {$entity}: {$count}\n";
            }

            $text .= "\nRecent Companies: {$recentCompanies}\n";
            $text .= "Recent People: {$recentPeople}\n";

            return $text."\nUse the available tools to search, create, update, or delete CRM records.";
        });

        return Response::text($overview);
    }
}
