<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class SearchCrmTool implements Tool
{
    public function description(): string
    {
        return 'Search across all CRM entity types (companies, people, opportunities, tasks, notes) by keyword. '
            .'Matches names, titles, and custom field values such as emails, phone numbers, links, and the text of notes and task descriptions. '
            .'Returns at most `limit` matches per entity type: when `truncated` is true for an entity there are more, '
            .'so never state a total from these results.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search keyword.')->required(),
            'limit' => $schema->integer()->description('Max results per entity type (default 5).')->default(5),
        ];
    }

    public function handle(Request $request): string
    {
        $query = LikePattern::escape((string) $request->string('query'));
        // Clamped at both ends like BaseReadListTool's per_page: limit 0 fetched one
        // row, sliced it away and then reported truncated=true, telling the model there
        // were more matches while handing it none.
        $limit = max(1, min((int) ($request['limit'] ?? 5), 10));
        /** @var User $user */
        $user = auth()->user();
        $workspace = $user->currentWorkspace;
        $tenantId = (string) $workspace->getKey();

        $results = [
            'companies' => Company::query()
                ->whereBelongsTo($workspace)
                ->where(fn (Builder $q): Builder => $q
                    ->where('name', 'ilike', "%{$query}%")
                    ->orWhereExists(CustomFieldValue::query()->matchingSearch(CrmEntity::Company, $tenantId, $query)->toBase()))
                ->limit($limit + 1)
                ->get(['id', 'name', 'created_at'])
                ->toArray(),
            'people' => People::query()
                ->whereBelongsTo($workspace)
                ->where(fn (Builder $q): Builder => $q
                    ->where('name', 'ilike', "%{$query}%")
                    ->orWhereExists(CustomFieldValue::query()->matchingSearch(CrmEntity::People, $tenantId, $query)->toBase()))
                ->limit($limit + 1)
                ->get(['id', 'name', 'company_id', 'created_at'])
                ->toArray(),
            'opportunities' => Opportunity::query()
                ->whereBelongsTo($workspace)
                ->where(fn (Builder $q): Builder => $q
                    ->where('name', 'ilike', "%{$query}%")
                    ->orWhereExists(CustomFieldValue::query()->matchingSearch(CrmEntity::Opportunity, $tenantId, $query)->toBase()))
                ->limit($limit + 1)
                ->get(['id', 'name', 'company_id', 'created_at'])
                ->toArray(),
            'tasks' => Task::query()
                ->whereBelongsTo($workspace)
                ->where(fn (Builder $q): Builder => $q
                    ->where('title', 'ilike', "%{$query}%")
                    ->orWhereExists(CustomFieldValue::query()->matchingSearch(CrmEntity::Task, $tenantId, $query)->toBase()))
                ->limit($limit + 1)
                ->get(['id', 'title', 'created_at'])
                ->toArray(),
            'notes' => Note::query()
                ->whereBelongsTo($workspace)
                ->where(fn (Builder $q): Builder => $q
                    ->where('title', 'ilike', "%{$query}%")
                    ->orWhereExists(CustomFieldValue::query()->matchingSearch(CrmEntity::Note, $tenantId, $query)->toBase()))
                ->limit($limit + 1)
                ->get(['id', 'title', 'created_at'])
                ->toArray(),
        ];

        // Fetched one past the cap per entity: anything still present after the
        // slice means there are more matches than shown. Without this the model
        // reads a capped list as the whole truth and states a wrong count, which
        // is the same ungrounded-number failure the list tools' `total` fixes.
        $truncated = [];

        foreach ($results as $entity => $rows) {
            $truncated[$entity] = count($rows) > $limit;
            $results[$entity] = array_slice($rows, 0, $limit);
        }

        $results['truncated'] = $truncated;

        return (string) json_encode($results, JSON_UNESCAPED_SLASHES);
    }
}
