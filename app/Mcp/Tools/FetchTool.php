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
use App\Support\CanonicalRecordUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('fetch')]
#[Description('Fetch a single CRM record by its canonical URL. Pair with the search tool for ChatGPT Company Knowledge citations.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class FetchTool extends Tool
{
    use Concerns\ChecksTokenAbility;

    /** @var array<string, array{0: class-string<Model>, 1: class-string<JsonResource>}> */
    private const array TYPE_MAP = [
        'company' => [Company::class, CompanyResource::class],
        'person' => [People::class, PeopleResource::class],
        'opportunity' => [Opportunity::class, OpportunityResource::class],
        'task' => [Task::class, TaskResource::class],
        'note' => [Note::class, NoteResource::class],
    ];

    public function __construct(private readonly CanonicalRecordUrl $urls) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Canonical record URL produced by the search tool, or any Relaticle record URL copied from the browser.')->required(),
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

        $parsed = $this->urls->parse((string) $validated['url']);

        if ($parsed === null || ! isset(self::TYPE_MAP[$parsed['type']])) {
            return Response::error("URL [{$validated['url']}] is not a recognized record URL.");
        }

        $type = $parsed['type'];
        $id = $parsed['id'];
        [$modelClass, $resourceClass] = self::TYPE_MAP[$type];

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
