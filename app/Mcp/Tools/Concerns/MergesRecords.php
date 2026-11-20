<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Actions\Merge\MergeRecords;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait MergesRecords
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return [
            'primary_id' => $schema->string()->description("The {$label} ID that wins wherever both records hold a value.")->required(),
            'secondary_id' => $schema->string()->description("The {$label} ID merged into the primary. Must differ from the primary.")->required(),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'merged' => $schema->boolean()->required(),
            'type' => $schema->string()->required(),
            'id' => $schema->string()->required(),
            'name' => $schema->string()->required(),
            'message' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, MergeRecords $mergeRecords): Response|ResponseFactory
    {
        foreach (['create', 'update', 'delete'] as $ability) {
            if (($denied = $this->denyIfTokenCannot($ability)) instanceof Response) {
                return $denied;
            }
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'primary_id' => ['required', 'string'],
            'secondary_id' => ['required', 'string', 'different:primary_id'],
        ]);

        $modelClass = $this->modelClass();
        $records = [];

        foreach (['primary_id', 'secondary_id'] as $key) {
            $records[$key] = $modelClass::query()->find($validated[$key]);

            if (! $records[$key] instanceof Company && ! $records[$key] instanceof People) {
                return Response::error("{$this->entityLabel()} with ID [{$validated[$key]}] not found.");
            }
        }

        try {
            $merged = $mergeRecords->execute($user, $records['primary_id'], $records['secondary_id']);
        } catch (ValidationException $exception) {
            return Response::error((string) collect($exception->errors())->flatten()->first());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return Response::error('You do not have permission to merge these records.');
        }

        $label = $this->entityLabel();
        $name = (string) $merged->{$this->nameAttribute()};

        return Response::structured([
            'merged' => true,
            'type' => strtolower($label),
            'id' => (string) $merged->getKey(),
            'name' => $name,
            'message' => "The two records were merged into the new {$label} '{$name}'. Both originals can no longer be read.",
        ]);
    }
}
