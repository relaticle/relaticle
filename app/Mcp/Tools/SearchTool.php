<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\CrmEntity;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use App\Support\LikePattern;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('search')]
#[Title('Search CRM')]
#[Description('Search across companies, people, opportunities, tasks, and notes. Returns canonical URLs suitable for ChatGPT Company Knowledge citation.')]
final class SearchTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function __construct(private readonly CanonicalRecordUrl $urls) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query (case-insensitive substring match across names, titles, and custom-field values, including note and task text). Max 255 chars.')->required(),
            'limit' => $schema->integer()->description('Max results per entity (default 5, max 20). Total payload up to 5×limit across companies, people, opportunities, tasks, and notes.')->default(5),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'results' => $schema->array()->items($schema->object())->required(),
            'count' => $schema->integer()->required(),
            'truncated' => $schema->object()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $limit = (int) ($validated['limit'] ?? 5);
        $query = LikePattern::escape($validated['query']);
        $workspace = $user->currentWorkspace;

        if (! $workspace instanceof Workspace) {
            return Response::error('No workspace is bound to this token.');
        }

        /** @var array<int, array{type: string, url: string, title: string, snippet: string}> $results */
        $results = [];

        /** @var array<string, bool> $truncated */
        $truncated = [];

        foreach (CrmEntity::cases() as $entity) {
            $modelClass = $entity->model();
            $field = $entity->titleColumn();
            $type = $entity->urlType();

            $hits = $modelClass::query()
                ->where('workspace_id', $workspace->getKey())
                ->where(fn (Builder $builder): Builder => $builder
                    ->where($field, 'ilike', "%{$query}%")
                    ->orWhereExists(CustomFieldValue::query()->matchingSearch($entity, (string) $workspace->getKey(), $query)->toBase()))
                ->orderBy($field)
                ->orderBy('id')
                ->limit($limit + 1)
                ->get();

            $truncated[$type] = $hits->count() > $limit;

            foreach ($hits->take($limit) as $hit) {
                if ($user->cannot('view', $hit)) {
                    continue;
                }

                $url = $this->urls->build($entity, (string) $hit->getKey(), $workspace);

                if ($url === null) {
                    continue;
                }

                $title = (string) $hit->getAttribute($field);

                $results[] = [
                    'type' => $type,
                    'url' => $url,
                    'title' => $title,
                    'snippet' => mb_substr($title, 0, 140),
                ];
            }
        }

        return Response::structured([
            'results' => $results,
            'count' => count($results),
            'truncated' => $truncated,
        ]);
    }
}
