<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Resources\V1\CompanyResource;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\OpportunityResource;
use App\Http\Resources\V1\PeopleResource;
use App\Http\Resources\V1\TaskResource;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Fetch a single CRM record by its canonical URL. Pair with the search tool for ChatGPT Company Knowledge citations.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class FetchTool extends Tool
{
    use Concerns\ChecksTokenAbility;

    /** @var array<string, array{0: string, 1: class-string<Model>, 2: class-string<JsonResource>}> */
    private const array SEGMENT_MAP = [
        'companies' => ['company', Company::class, CompanyResource::class],
        'people' => ['person', People::class, PeopleResource::class],
        'opportunities' => ['opportunity', Opportunity::class, OpportunityResource::class],
        'tasks' => ['task', Task::class, TaskResource::class],
        'notes' => ['note', Note::class, NoteResource::class],
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Canonical record URL produced by the search tool.')->required(),
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
            'url' => ['required', 'url'],
        ]);

        $path = parse_url((string) $validated['url'], PHP_URL_PATH) ?: '';
        $segments = array_values(array_filter(explode('/', $path)));

        if (count($segments) < 3 || $segments[0] !== 'app' || ! isset(self::SEGMENT_MAP[$segments[1]])) {
            return Response::error("URL [{$validated['url']}] is not a recognized record URL.");
        }

        [$type, $modelClass, $resourceClass] = self::SEGMENT_MAP[$segments[1]];
        $id = $segments[2];

        /** @var class-string<Model> $modelClass */
        $model = $modelClass::query()->find($id);

        if (! $model instanceof Model) {
            return Response::error("Record [{$id}] not found.");
        }

        if ($user->cannot('view', $model)) {
            return Response::error('You do not have permission to view this record.');
        }

        $model->loadMissing('customFieldValues.customField.options');

        /** @var class-string<JsonResource> $resourceClass */
        $resource = new $resourceClass($model);

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode((string) $resource->toJson(), true);

        /** @var array<string, mixed> $data */
        $data = $envelope['data'] ?? $envelope;

        return Response::structured([
            'type' => $type,
            'url' => $validated['url'],
            'data' => $data,
        ]);
    }
}
