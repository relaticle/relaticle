<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\CanonicalRecordUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search')]
#[Description('Search across companies, people, opportunities, tasks, and notes. Returns canonical URLs suitable for ChatGPT Company Knowledge citation.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class SearchTool extends Tool
{
    use ChecksTokenAbility;

    /**
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    private const array ENTITY_MAP = [
        'company' => [Company::class, 'name'],
        'person' => [People::class, 'name'],
        'opportunity' => [Opportunity::class, 'name'],
        'task' => [Task::class, 'title'],
        'note' => [Note::class, 'title'],
    ];

    public function __construct(private readonly CanonicalRecordUrl $urls) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query (case-insensitive substring match across name/title fields). Max 255 chars.')->required(),
            'limit' => $schema->integer()->description('Max results per entity (default 5, max 20). Total payload up to 5×limit across companies, people, opportunities, tasks, and notes.')->default(5),
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
        $query = $validated['query'];
        $team = $user->currentTeam;

        if (! $team instanceof Team) {
            return Response::error('No team is bound to this token.');
        }

        /** @var array<int, array{type: string, url: string, title: string, snippet: string}> $results */
        $results = [];

        foreach (self::ENTITY_MAP as $type => [$modelClass, $field]) {
            $hits = $modelClass::query()
                ->where($field, 'ilike', '%'.$query.'%')
                ->limit($limit)
                ->get();

            foreach ($hits as $hit) {
                if ($user->cannot('view', $hit)) {
                    continue;
                }

                $url = $this->urls->build($type, (string) $hit->getKey(), $team);

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

        return Response::structured(['results' => $results, 'count' => count($results)]);
    }
}
