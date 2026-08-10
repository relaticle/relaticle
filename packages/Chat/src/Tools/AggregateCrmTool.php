<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Actions\Opportunity\AggregateOpportunities;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AggregateCrmTool implements Tool
{
    public function description(): string
    {
        return 'Aggregate opportunities by stage or company: count and total pipeline value per group, with optional date range. Use for "pipeline by stage", "deals by company", "total value", "how many deals" questions.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'group_by' => $schema->string()->description('How to group results. Use "stage" to see pipeline by deal stage, or "company" to see deals grouped by company.'),
            'date_from' => $schema->string()->description('Optional start date (YYYY-MM-DD). Only include opportunities created on or after this date.'),
            'date_to' => $schema->string()->description('Optional end date (YYYY-MM-DD). Only include opportunities created on or before this date.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $groupBy = (string) ($request['group_by'] ?? 'stage');
        $dateFrom = isset($request['date_from']) && is_string($request['date_from']) ? $request['date_from'] : null;
        $dateTo = isset($request['date_to']) && is_string($request['date_to']) ? $request['date_to'] : null;

        try {
            $result = resolve(AggregateOpportunities::class)->execute(
                user: $user,
                groupBy: $groupBy,
                dateFrom: $dateFrom,
                dateTo: $dateTo,
            );
        } catch (HttpException $e) {
            return (string) json_encode(['error' => $e->getMessage()]);
        }

        return (string) json_encode($result, JSON_PRETTY_PRINT);
    }
}
