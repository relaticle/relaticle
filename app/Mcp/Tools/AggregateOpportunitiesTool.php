<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Opportunity\AggregateOpportunities;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Aggregate Opportunities')]
#[Description('Aggregate opportunity counts and pipeline amount by stage or company, with optional creation-date bounds.')]
final class AggregateOpportunitiesTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    public function __construct(
        private readonly AggregateOpportunities $aggregate,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->description('Group opportunities by stage or company.')->required(),
            'date_from' => $schema->string()->description('Include opportunities created on or after this date (YYYY-MM-DD).'),
            'date_to' => $schema->string()->description('Include opportunities created on or before this date (YYYY-MM-DD).'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->required(),
            'rows' => $schema->array()->items($schema->object())->required(),
            'total_count' => $schema->integer()->required(),
            'total_amount' => $schema->number()->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        $validated = $request->validate([
            'group_by' => ['required', 'string', Rule::in(['stage', 'company'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return Response::structured($this->aggregate->execute(
            $user,
            $validated['group_by'],
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        ));
    }
}
