<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;

#[Description('Get an overview of the CRM data for the current team, including record counts and recent activity.')]
final class CrmOverviewPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
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

        $overview = "CRM Overview for current team:\n\n";
        $overview .= "Record Counts:\n";

        foreach ($counts as $entity => $count) {
            $overview .= "  - {$entity}: {$count}\n";
        }

        $overview .= "\nRecent Companies: {$recentCompanies}\n";
        $overview .= "Recent People: {$recentPeople}\n";
        $overview .= "\nUse the available tools to search, create, update, or delete CRM records.";

        return Response::text($overview);
    }
}
