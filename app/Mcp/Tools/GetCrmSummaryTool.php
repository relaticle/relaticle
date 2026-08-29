<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Crm\GetCrmSummary;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Get CRM Summary')]
#[Description('Get workspace record counts, opportunity pipeline totals by stage, and user-timezone-aware task due status.')]
final class GetCrmSummaryTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function __construct(
        private readonly GetCrmSummary $summary,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'as_of' => $schema->object()->required(),
            'companies' => $schema->object()->required(),
            'people' => $schema->object()->required(),
            'opportunities' => $schema->object()->required(),
            'tasks' => $schema->object()->required(),
            'notes' => $schema->object()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = $request->user();

        return Response::structured($this->summary->execute($user));
    }
}
