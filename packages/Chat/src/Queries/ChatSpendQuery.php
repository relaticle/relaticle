<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\ModelRegistry;

/**
 * @phpstan-type ModelSpend array{rows: int, prompt_tokens: int, cache_read_tokens: int, cache_write_tokens: int, output_tokens: int, estimated_cost: float|null}
 * @phpstan-type Spend array{
 *   by_model: array<string, ModelSpend>,
 *   rows_without_tokens: int,
 *   estimated_cost: float,
 *   top_workspaces: list<array{workspace_id: string, estimated_cost: float}>
 * }
 */
final readonly class ChatSpendQuery
{
    private const int TOP_WORKSPACES = 5;

    public function __construct(private ModelRegistry $models) {}

    /** @return Spend */
    public function forWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byModel = [];
        $byWorkspace = [];
        $withoutTokens = 0;

        $rows = AiCreditTransaction::query()
            ->whereBetween('created_at', [$from, $to])
            ->where(fn (Builder $query): Builder => $query->where('input_tokens', '>', 0)->orWhere('output_tokens', '>', 0)->orWhereNotNull('metadata->tokens'))
            ->lazyById(500, 'id');

        foreach ($rows as $row) {
            $tokens = is_array($row->metadata['tokens'] ?? null) ? $row->metadata['tokens'] : null;

            if ($tokens === null) {
                $withoutTokens++;

                continue;
            }

            $prompt = (int) ($tokens['prompt'] ?? 0);
            $read = (int) ($tokens['cache_read'] ?? 0);
            $write = (int) ($tokens['cache_write'] ?? 0);
            $cost = $this->cost((string) $row->model, $prompt, $read, $write, (int) $row->output_tokens);

            $model = $byModel[$row->model] ?? ['rows' => 0, 'prompt_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'output_tokens' => 0, 'estimated_cost' => null];

            $byModel[$row->model] = [
                'rows' => $model['rows'] + 1,
                'prompt_tokens' => $model['prompt_tokens'] + $prompt,
                'cache_read_tokens' => $model['cache_read_tokens'] + $read,
                'cache_write_tokens' => $model['cache_write_tokens'] + $write,
                'output_tokens' => $model['output_tokens'] + (int) $row->output_tokens,
                'estimated_cost' => $cost === null ? $model['estimated_cost'] : ($model['estimated_cost'] ?? 0.0) + $cost,
            ];

            if ($cost !== null) {
                $byWorkspace[(string) $row->workspace_id] = ($byWorkspace[(string) $row->workspace_id] ?? 0.0) + $cost;
            }
        }

        arsort($byWorkspace);

        return [
            'by_model' => array_map(
                static fn (array $model): array => [...$model, 'estimated_cost' => $model['estimated_cost'] === null ? null : round($model['estimated_cost'], 2)],
                $byModel,
            ),
            'rows_without_tokens' => $withoutTokens,
            'estimated_cost' => round(array_sum($byWorkspace), 2),
            'top_workspaces' => array_map(
                static fn (string $workspaceId, float $cost): array => ['workspace_id' => $workspaceId, 'estimated_cost' => round($cost, 2)],
                array_keys(array_slice($byWorkspace, 0, self::TOP_WORKSPACES, true)),
                array_slice($byWorkspace, 0, self::TOP_WORKSPACES, true),
            ),
        ];
    }

    private function cost(string $model, int $prompt, int $read, int $write, int $output): ?float
    {
        $rates = $this->models->ratesFor($model);

        if ($rates === null) {
            return null;
        }

        $uncached = max($prompt - $read - $write, 0);
        $cached = $read * (float) config('chat.cache_cost_factors.read', 0.1) + $write * (float) config('chat.cache_cost_factors.write', 1.25);

        return (($uncached + $cached) * $rates['input_per_mtok'] + $output * $rates['output_per_mtok']) / 1_000_000;
    }
}
