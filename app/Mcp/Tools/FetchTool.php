<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\CrmEntity;
use App\Http\Resources\V1\CompanyResource;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\OpportunityResource;
use App\Http\Resources\V1\PeopleResource;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
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
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('fetch')]
#[Title('Fetch CRM Record')]
#[Description('Fetch a single CRM record by its canonical URL. Pair with the search tool for ChatGPT Company Knowledge citations.')]
final class FetchTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function __construct(private readonly CanonicalRecordUrl $urls) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Canonical record URL produced by the search tool, or any Relaticle record URL copied from the browser.')->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->required(),
            'url' => $schema->string()->required(),
            'data' => $schema->object()->required(),
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

        if ($parsed === null) {
            return Response::error("URL [{$validated['url']}] is not a recognized record URL.");
        }

        $entity = $parsed['entity'];
        $id = $parsed['id'];

        $resourceClass = match ($entity) {
            CrmEntity::Company => CompanyResource::class,
            CrmEntity::People => PeopleResource::class,
            CrmEntity::Opportunity => OpportunityResource::class,
            CrmEntity::Task => TaskResource::class,
            CrmEntity::Note => NoteResource::class,
        };

        $modelClass = $entity->model();
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
        $envelope = (array) json_decode((string) $resource->toJson(), flags: JSON_THROW_ON_ERROR);

        $data = $envelope['data'] ?? (object) $envelope;

        return Response::structured([
            'type' => $entity->urlType(),
            'url' => $validated['url'],
            'data' => $data,
        ]);
    }
}
