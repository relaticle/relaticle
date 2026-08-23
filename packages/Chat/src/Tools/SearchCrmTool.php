<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\LikePattern;

final class SearchCrmTool implements Tool
{
    /**
     * Custom field types whose stored value is an option id or markup, never free text.
     *
     * @var list<string>
     */
    private const array EXCLUDED_CUSTOM_FIELD_TYPES = [
        'select',
        'multi-select',
        'radio',
        'checkbox-list',
        'tags-input',
        'rich-editor',
        'markdown-editor',
    ];

    public function description(): string
    {
        return 'Search across all CRM entity types (companies, people, opportunities, tasks, notes) by keyword. '
            .'Matches names, titles, and custom field values such as emails, phone numbers, and links.';
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
        $limit = min((int) ($request['limit'] ?? 5), 10);
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;
        $tenantId = (string) $team->getKey();

        $results = [
            'companies' => Company::query()
                ->whereBelongsTo($team)
                ->where(function (Builder $q) use ($query, $tenantId): void {
                    $q->where('name', 'ilike', "%{$query}%");
                    $this->orMatchesCustomFields($q, 'company', 'companies', $query, $tenantId);
                })
                ->limit($limit)
                ->get(['id', 'name', 'created_at'])
                ->toArray(),
            'people' => People::query()
                ->whereBelongsTo($team)
                ->where(function (Builder $q) use ($query, $tenantId): void {
                    $q->where('name', 'ilike', "%{$query}%");
                    $this->orMatchesCustomFields($q, 'people', 'people', $query, $tenantId);
                })
                ->limit($limit)
                ->get(['id', 'name', 'company_id', 'created_at'])
                ->toArray(),
            'opportunities' => Opportunity::query()
                ->whereBelongsTo($team)
                ->where(function (Builder $q) use ($query, $tenantId): void {
                    $q->where('name', 'ilike', "%{$query}%");
                    $this->orMatchesCustomFields($q, 'opportunity', 'opportunities', $query, $tenantId);
                })
                ->limit($limit)
                ->get(['id', 'name', 'company_id', 'created_at'])
                ->toArray(),
            'tasks' => Task::query()
                ->whereBelongsTo($team)
                ->where(function (Builder $q) use ($query, $tenantId): void {
                    $q->where('title', 'ilike', "%{$query}%");
                    $this->orMatchesCustomFields($q, 'task', 'tasks', $query, $tenantId);
                })
                ->limit($limit)
                ->get(['id', 'title', 'created_at'])
                ->toArray(),
            'notes' => Note::query()
                ->whereBelongsTo($team)
                ->where(function (Builder $q) use ($query, $tenantId): void {
                    $q->where('title', 'ilike', "%{$query}%");
                    $this->orMatchesCustomFields($q, 'note', 'notes', $query, $tenantId);
                })
                ->limit($limit)
                ->get(['id', 'title', 'created_at'])
                ->toArray(),
        ];

        return (string) json_encode($results, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Adds a single OR-EXISTS clause matching this entity's searchable custom field values.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $builder
     */
    private function orMatchesCustomFields(Builder $builder, string $entityType, string $table, string $query, string $tenantId): void
    {
        $builder->orWhereExists(function (QueryBuilder $sub) use ($entityType, $table, $query, $tenantId): void {
            $sub->selectRaw('1')
                ->from('custom_field_values as cfv')
                ->join('custom_fields as cf', 'cf.id', '=', 'cfv.custom_field_id')
                ->whereColumn('cfv.entity_id', "{$table}.id")
                ->where('cfv.entity_type', $entityType)
                ->where('cfv.tenant_id', $tenantId)
                ->whereNotIn('cf.type', self::EXCLUDED_CUSTOM_FIELD_TYPES)
                ->where(function (QueryBuilder $w) use ($query): void {
                    $w->where('cfv.text_value', 'ilike', "%{$query}%")
                        ->orWhere('cfv.string_value', 'ilike', "%{$query}%")
                        ->orWhereRaw(
                            "cfv.json_value is not null and json_typeof(cfv.json_value) = 'array' and exists (select 1 from json_array_elements_text(cfv.json_value) as elem(val) where elem.val ilike ?)",
                            ["%{$query}%"],
                        );
                });
        });
    }
}
